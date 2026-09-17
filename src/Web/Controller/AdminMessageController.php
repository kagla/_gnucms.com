<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\Aligo\MessageText;
use GnuCms\Aligo\PhoneNumber;
use GnuCms\Aligo\TransportFailure;
use GnuCms\Aligo\Variables;
use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 운영 › 알림톡·문자 화면. 발송·템플릿·이력 3개 탭을 모두 이 컨트롤러가 담당한다.
 * 발송 탭에서는 여기서 메시지를 만들거나 승인하지 않는다 — 실제 거절(미승인
 * 템플릿·빈 변수·꺼진 채널)은 모두 AligoService::send() 가 맡고, 이 화면은 그 앞에서
 * 무엇이 나갈지 보여줄 뿐이다. 이력 탭은 알리고에 결과 웹훅이 없어 History::refresh()
 * 를 화면을 열 때마다 조용히 한 번 불러 조금씩 갱신한다 — 실패해도 목록은 저장된
 * 값으로 그대로 보여주고, 다음 방문에서 다시 시도한다.
 */
final class AdminMessageController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function templates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        $notice = is_string($query['notice'] ?? null) ? $query['notice'] : null;

        return $this->render($request, $response, null, $notice);
    }

    public function fetchTemplates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $counts = $this->app->aligo()->templates->fetch();
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render($request, $response->withStatus(422), $this->firstError($e));
        }

        $notice = sprintf(
            '가져오기 %d건, 갱신 %d건, 사용 중지 %d건', $counts['imported'], $counts['updated'], $counts['disabled']
        );

        return $this->redirect($request, $response, 'admin.messages.templates', ['notice' => $notice]);
    }

    public function toggleTemplate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->aligo()->templates->setEnabled(
                (string) ($input['tpl_code'] ?? ''), ($input['action'] ?? '') === 'enable'
            );
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render($request, $response->withStatus(422), $this->firstError($e));
        }

        return $this->redirect($request, $response, 'admin.messages.templates');
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        $notice = is_string($query['notice'] ?? null) ? $query['notice'] : null;

        return $this->renderSend($request, $response, [], null, [], null, $notice);
    }

    /**
     * 미리보기. collect() 로 만든 첫 수신자의 본문만 Variables::apply() 로 채워 보여주고
     * 절대 보내지 않는다 — AligoService::send() 는 여기서 부르지 않는다.
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();

        $collected = $this->collect($input);
        try {
            $preview = $this->buildPreview($collected);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->renderSend(
                $request, $response->withStatus(422), $input, $this->firstError($e), $e->details(), null, null
            );
        }

        return $this->renderSend($request, $response, $input, null, [], $preview, null);
    }

    /** 실제 발송. collect() 가 만든 요청을 그대로 AligoService::send() 하나에만 넘긴다. */
    public function dispatch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();

        $collected = $this->collect($input);
        try {
            $jobId = $this->app->aligo()->send($collected['request']);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->renderSend(
                $request, $response->withStatus(422), $input, $this->firstError($e), $e->details(), null, null
            );
        }

        // 방금 만든 작업의 이력 상세로 보낸다. 알리고는 결과 웹훅이 없으므로 이 시점엔
        // 아직 결과를 모른다 — 그 화면 자체가 "결과를 기다리는 중"이라고 정직하게 말한다.
        return $this->redirect($request, $response, 'admin.messages.history.detail', [
            'notice' => sprintf('발송을 시작했습니다. 작업 번호 #%d.', $jobId),
        ], ['id' => (string) $jobId]);
    }

    /**
     * 이력 목록. 화면을 그리기 전에 결과 조회를 한 번 시도한다 — 알리고가 웹훅을 주지
     * 않으므로 관리자가 들를 때마다 조금씩 갱신하는 것이 유일한 갱신 수단이다. 조회
     * 자체가 실패해도(알리고 접속 불가 등) 목록은 저장된 값 그대로 보여준다.
     */
    public function history(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->aligo()->history->refresh();
        } catch (DomainError | TransportFailure $e) {
            // 조회에 실패해도 이력 목록은 보여준다. 다음 방문에 다시 시도한다.
        }
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $listing = $this->app->aligo()->history->jobs($page);
        $listing['items'] = array_map([$this, 'withTemplateLabel'], $listing['items']);

        return View::fromRequest($request)->render($response, 'admin/message/history', [
            'listing' => $listing,
            'pending' => $this->app->aligo()->history->pendingCount(),
            'query' => $request->getQueryParams(),
        ]);
    }

    /**
     * 관리자가 손으로 한 번 더 결과를 조회한다. 실패해도(알리고 접속 불가 등) 화면은
     * 깨지지 않고 목록으로 그대로 돌아간다 — 다음 방문에서 또 시도할 수 있다.
     */
    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->aligo()->history->refresh();
        } catch (DomainError | TransportFailure $e) {
            // 갱신 실패도 조용히 넘어간다. 목록은 저장된 값으로 다시 그려진다.
        }
        $page = max(1, (int) ($input['page'] ?? 1));

        return $this->redirect(
            $request, $response, 'admin.messages.history', $page > 1 ? ['page' => $page] : []
        );
    }

    /** 작업 하나의 상세. 수신자별 결과를 전체 번호로 보여준다(목록과 달리 여기서만 전체를 보여준다). */
    public function historyDetail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $this->app->guestAcl()->assertGlobalAdmin();
        $job = $this->app->aligo()->history->job((int) $args['id']);
        if ($job === null) {
            throw DomainError::notFound('이력을 찾을 수 없습니다.');
        }
        $job = $this->withTemplateLabel($job);
        $job['sender_display'] = PhoneNumber::format((string) $job['sender']);
        $job['recipients'] = array_map(static function (array $r): array {
            // 상세 화면에서만 전체 번호를 보여준다 — 목록에서는 절대 이 값을 쓰지 않는다.
            $r['phone_display'] = PhoneNumber::format((string) $r['phone']);

            return $r;
        }, $job['recipients']);
        $query = $request->getQueryParams();

        return View::fromRequest($request)->render($response, 'admin/message/history_detail', [
            'job' => $job,
            'notice' => is_string($query['notice'] ?? null) ? $query['notice'] : null,
        ]);
    }

    /**
     * 목록 표의 "템플릿" 칸에 쓸 이름. 알림톡만 템플릿을 쓰므로 문자 작업은 null 로 둬
     * 뷰가 "-"를 보여주게 한다. 사본이 지워지거나 못 찾으면 코드라도 그대로 보여준다.
     */
    private function withTemplateLabel(array $job): array
    {
        $tplCode = (string) ($job['tpl_code'] ?? '');
        if ($job['channel'] !== 'at' || $tplCode === '') {
            $job['template_label'] = null;

            return $job;
        }
        $template = $this->app->aligo()->templates->find($tplCode);
        $job['template_label'] = $template !== null ? (string) $template['name'] : $tplCode;

        return $job;
    }

    /** 화면 입력을 Dispatch 가 받는 모양으로 바꾼다. 미리보기와 발송이 같은 것을 쓴다. */
    private function collect(array $input): array
    {
        $vars = [];
        foreach ($input as $name => $value) {
            if (str_starts_with((string) $name, 'var_')) {
                $vars[substr((string) $name, 4)] = (string) $value;
            }
        }

        $recipients = [];
        $skipped = 0;
        $ineligible = 0;
        foreach ((array) ($input['members'] ?? []) as $userId) {
            $row = $this->app->db()->selectOne('SELECT id, display_name, phone, status FROM '
                . $this->app->db()->table('users') . ' WHERE id = ?', [(string) $userId]);
            if ($row === null) {
                $skipped++;
                continue;
            }
            // 탈퇴·차단 회원은 번호가 남아 있어도(탈퇴 처리는 번호를 지우지 않는다) 고를 수
            // 없다 — CommentService 가 명시하는 원칙과 같다: "차단된 회원은 없는 회원과
            // 같게 다룬다", 이 화면 밖의 모든 회원용 게이트도 status === 'active' 만 통과
            // 시킨다. "번호 없음"과 다른 사유이므로 같은 집계에 섞지 않는다 — 관리자가
            // 어느 쪽인지 구분해서 볼 수 있어야 한다.
            if ((string) $row['status'] !== 'active') {
                $ineligible++;
                continue;
            }
            if (($row['phone'] ?? '') === '') {
                $skipped++;
                continue;
            }
            $recipients[] = ['phone' => (string) $row['phone'], 'name' => (string) $row['display_name'],
                'user_id' => (string) $row['id'], 'vars' => $vars];
        }
        foreach (preg_split('/[\r\n,]+/', (string) ($input['numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $recipients[] = ['phone' => $line, 'vars' => $vars];
            }
        }

        return [
            'request' => [
                'channel' => (string) ($input['channel'] ?? 'sms'),
                'body' => (string) ($input['body'] ?? ''),
                'title' => (string) ($input['title'] ?? ''),
                'tpl_code' => (string) ($input['tpl_code'] ?? ''),
                'failover' => ($input['failover'] ?? '') === '1',
                'created_by' => $this->app->guestAcl()->identity()->displayName() ?? '',
                'recipients' => $recipients,
            ],
            'skipped' => $skipped,
            'ineligible' => $ineligible,
        ];
    }

    /**
     * 미리보기에 보여줄 값. 실제로 보내질 첫 수신자의 본문을 Variables::apply() 로 그대로
     * 채운다 — 빈 변수 거절은 그 함수 하나가 맡으므로 여기서 따로 다시 검사하지 않는다.
     * 알림톡 템플릿의 승인 여부 같은 발송 가능 판정도 마찬가지로 여기서 다시 하지 않는다 —
     * 그건 실제로 보낼 때 AligoService::send() 가 판정한다. 이 화면은 지금 저장된 사본
     * 내용을 그대로 읽어 보여줄 뿐이다.
     */
    private function buildPreview(array $collected): array
    {
        $request = $collected['request'];
        $recipients = $request['recipients'];
        $body = $request['channel'] === 'at' ? $this->templateBody($request['tpl_code']) : $request['body'];

        $sample = $recipients === [] ? null : Variables::apply($body, (array) $recipients[0]['vars']);

        return [
            'sample' => $sample,
            'count' => count($recipients),
            'skipped' => $collected['skipped'],
            'ineligible' => $collected['ineligible'],
            'bytes' => $sample === null ? null : MessageText::byteLength($sample),
            'classify' => $sample === null ? null : MessageText::channelFor($sample),
        ];
    }

    /** 알림톡 템플릿 사본의 본문. 코드가 비어 있거나 가져온 적 없으면 빈 문자열이다. */
    private function templateBody(string $tplCode): string
    {
        if ($tplCode === '') {
            return '';
        }
        $template = $this->app->aligo()->templates->find($tplCode);

        return $template !== null ? (string) $template['content'] : '';
    }

    /** 변수 칸(var_이름)을 만들 이름 목록. 알림톡은 고른 템플릿에서, 문자는 입력한 본문에서 뽑는다. */
    private function variableNames(array $input): array
    {
        $channel = (string) ($input['channel'] ?? 'sms');
        $body = $channel === 'at'
            ? $this->templateBody((string) ($input['tpl_code'] ?? ''))
            : (string) ($input['body'] ?? '');

        return Variables::names($body);
    }

    /**
     * 입력에 담긴 회원 ID 각각의 이름·번호. 검색어를 다시 치지 않아도 "선택됨" 표시를
     * 유지한다. 검색 결과와 같은 이유로 활성 회원만 보여준다 — 체크한 뒤 검증 오류로
     * 다시 그릴 때까지 그 사이에 차단되거나 탈퇴했을 수도 있고, 그런 회원은 이 목록에도
     * 더는 남지 않아야 한다. 걸러진 회원은 사라지는 대신 미리보기의 "보낼 수 없는 회원"
     * 집계에 잡힌다.
     */
    private function selectedMembers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->app->db()->select('SELECT id, display_name, phone FROM '
            . $this->app->db()->table('users')
            . ' WHERE id IN (' . $placeholders . ') AND status = ? ORDER BY display_name',
            [...$ids, 'active']);

        return array_map([$this, 'withPhoneDisplay'], $rows);
    }

    /**
     * 이름·이메일로 회원을 찾는다. 이미 선택된 회원은 "선택된 회원" 목록에 있으므로 여기
     * 또 보여주지 않는다. 활성 회원만 보여준다 — CommentService 의 원칙("차단된 회원은
     * 없는 회원과 같게 다룬다")과 같게, 로그인·글쓰기·댓글 등 이 코드베이스의 모든
     * 회원용 게이트가 요구하는 status === 'active' 를 여기서도 그대로 따른다. 탈퇴
     * 처리는 이름·이메일을 익명화할 뿐 번호는 지우지 않으므로, 걸러 두지 않으면 검색으로
     * 다시 찾아 고를 수 있다.
     */
    private function searchMembers(string $q, array $excludeIds): array
    {
        if ($q === '') {
            return [];
        }
        $needle = '%' . mb_strtolower($q) . '%';
        $rows = $this->app->db()->select('SELECT id, display_name, phone, email FROM '
            . $this->app->db()->table('users')
            . ' WHERE (LOWER(display_name) LIKE ? OR LOWER(email) LIKE ?) AND status = ?'
            . ' ORDER BY id DESC LIMIT 20',
            [$needle, $needle, 'active']);
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => !in_array((string) $row['id'], $excludeIds, true)
        ));

        return array_map([$this, 'withPhoneDisplay'], $rows);
    }

    /** 화면에 보여줄 번호 표시값을 붙인다. 번호가 없으면 null 로 둬 뷰가 "번호 없음"을 보여주게 한다. */
    private function withPhoneDisplay(array $row): array
    {
        $phone = (string) ($row['phone'] ?? '');
        $row['phone_display'] = $phone === '' ? null : PhoneNumber::format($phone);

        return $row;
    }

    private function renderSend(ServerRequestInterface $request, ResponseInterface $response, array $input,
        ?string $error, array $fieldErrors, ?array $preview, ?string $notice): ResponseInterface
    {
        $selectedIds = array_map('strval', (array) ($input['members'] ?? []));
        $query = $request->getQueryParams();
        $searchQuery = is_string($query['q'] ?? null) ? trim($query['q']) : '';

        return View::fromRequest($request)->render($response, 'admin/message/send', [
            'values' => $input,
            'templates' => $this->app->aligo()->templates->usable(),
            'status' => $this->app->aligo()->status(),
            'search_query' => $searchQuery,
            'search_results' => $this->searchMembers($searchQuery, $selectedIds),
            'selected_members' => $this->selectedMembers($selectedIds),
            'variable_names' => $this->variableNames($input),
            'preview' => $preview,
            'error' => $error,
            'field_errors' => $fieldErrors,
            'notice' => $notice,
        ]);
    }

    /** 필드 오류 배열에서 화면 상단 알림에 쓸 문장 하나를 고른다. */
    private function firstError(DomainError $e): string
    {
        $details = $e->details();

        return $details === [] ? $e->getMessage() : (string) reset($details);
    }

    private function render(ServerRequestInterface $request, ResponseInterface $response,
        ?string $error, ?string $notice = null): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/message/templates', [
            'copies' => $this->app->aligo()->templates->all(),
            'error' => $error,
            'notice' => $notice,
        ]);
    }

    private function input(ServerRequestInterface $request): array
    {
        $input = $request->getParsedBody();

        return is_array($input) ? $input : [];
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }

    private function redirect(ServerRequestInterface $request, ResponseInterface $response, string $route,
        array $query = [], array $routeParams = []): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route, $routeParams);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
