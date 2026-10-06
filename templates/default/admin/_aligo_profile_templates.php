<?php
$templateStatusLabels = ['A' => '정상', 'S' => '중단', 'R' => '대기'];
$templateInspectionLabels = ['REG' => '등록', 'REQ' => '검수 중', 'APR' => '승인', 'REJ' => '반려'];
$templateTypeLabels = ['BA' => '기본형', 'EX' => '부가정보형', 'AD' => '광고추가형', 'MI' => '복합형'];
$profileTemplateIndex = 0;
?>
<section class="form-section" aria-label="채널별 알림톡 템플릿">
  <h2 class="form-section-title">채널별 알림톡 템플릿</h2>
  <p class="fieldset-label">검수 중·반려·중단된 템플릿도 함께 표시합니다. 승인되고 중단되지 않은 템플릿은 <a href="<?= $this->url('admin.messages.templates') ?>">템플릿 관리</a>에서 가져온 뒤 사용으로 설정할 수 있습니다.</p>
  <?php foreach ($profiles as $profile): ?>
    <?php
      $profileTemplates = $profile['templates'] ?? [];
      $templateError = $profile['templates_error'] ?? null;
      $profileName = (string) ($profile['name'] ?? '');
      $approvedCount = count(array_filter($profileTemplates, static fn (array $template): bool =>
        \GnuCms\Aligo\Templates::approved((string) ($template['status'] ?? ''), (string) ($template['inspStatus'] ?? ''))));
    ?>
    <section class="form-section" aria-label="<?= $this->e($profileName !== '' ? $profileName : '카카오채널') ?> 템플릿">
      <h3 class="form-section-title"><?= $this->e($profileName !== '' ? $profileName : '카카오채널') ?></h3>
      <?php if ($templateError !== null): ?>
        <div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span>템플릿을 불러오지 못했습니다. <?= $this->e($templateError) ?> 채널 불러오기를 다시 눌러 주세요.</span></div>
      <?php else: ?>
        <p class="fieldset-label">전체 <?= count($profileTemplates) ?>개 · 승인 <?= $approvedCount ?>개 (중단 제외)</p>
        <div class="table-wrap">
          <table class="table table-zebra">
            <caption class="sr-only"><?= $this->e($profileName) ?> 전체 알림톡 템플릿</caption>
            <thead><tr><th>코드</th><th>이름</th><th>유형</th><th>상태</th><th>검수 상태</th><th>내용</th></tr></thead>
            <tbody>
              <?php if ($profileTemplates === []): ?>
                <tr class="table-empty"><td colspan="6">이 채널에 등록된 템플릿이 없습니다.</td></tr>
              <?php else: foreach ($profileTemplates as $template): ?>
                <?php
                  $code = (string) ($template['templtCode'] ?? '');
                  $name = (string) ($template['templtName'] ?? '');
                  $type = (string) ($template['templateType'] ?? '');
                  $templateStatus = (string) ($template['status'] ?? '');
                  $inspection = (string) ($template['inspStatus'] ?? '');
                  $buttons = is_array($template['buttons'] ?? null) ? $template['buttons'] : [];
                  $detailId = 'aligo-profile-template-detail-' . ++$profileTemplateIndex;
                ?>
                <tr>
                  <td data-label="코드"><code><?= $this->e($code) ?></code></td>
                  <td data-label="이름"><?= $this->e($name) ?></td>
                  <td data-label="유형"><?= $this->e($templateTypeLabels[$type] ?? ($type !== '' ? $type : '확인 불가')) ?></td>
                  <td data-label="상태"><span class="badge badge-sm <?= $templateStatus === 'A' ? 'badge-success' : ($templateStatus === 'S' ? 'badge-error' : 'badge-ghost') ?> badge-soft"><?= $this->e($templateStatusLabels[$templateStatus] ?? ($templateStatus !== '' ? $templateStatus : '확인 불가')) ?></span></td>
                  <td data-label="검수 상태"><span class="badge badge-sm <?= $inspection === 'APR' ? 'badge-success' : ($inspection === 'REJ' ? 'badge-error' : 'badge-ghost') ?> badge-soft"><?= $this->e($templateInspectionLabels[$inspection] ?? ($inspection !== '' ? $inspection : '확인 불가')) ?></span></td>
                  <td data-label="내용">
                    <button class="btn btn-outline btn-sm" type="button" data-aligo-template-detail="<?= $this->e($detailId) ?>" data-template-title="<?= $this->e($name . ' (' . $code . ')') ?>" aria-haspopup="dialog" aria-controls="aligo-profile-template-modal" aria-label="<?= $this->e($name . ' (' . $code . ')') ?> 본문·버튼 보기">본문·버튼 보기</button>
                    <template id="<?= $this->e($detailId) ?>">
                      <p class="card-sub"><?= $this->e($profileName) ?> · <?= $this->e($templateStatusLabels[$templateStatus] ?? $templateStatus) ?> · <?= $this->e($templateInspectionLabels[$inspection] ?? $inspection) ?></p>
                      <h4 class="form-section-title">본문</h4>
                      <?php if ((string) ($template['templtTitle'] ?? '') !== ''): ?><p><strong><?= $this->e($template['templtTitle']) ?></strong></p><?php endif ?>
                      <?php if ((string) ($template['templtSubtitle'] ?? '') !== ''): ?><p><?= $this->e($template['templtSubtitle']) ?></p><?php endif ?>
                      <pre class="tpl-detail-content" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:24rem;overflow:auto"><?= $this->e($template['templtContent'] ?? '') ?></pre>
                      <?php if ((string) ($template['templtImageUrl'] ?? '') !== ''): ?><p style="overflow-wrap:anywhere">이미지: <?= $this->e($template['templtImageName'] ?? '') ?> <?= $this->e($template['templtImageUrl']) ?></p><?php endif ?>
                      <p class="fieldset-label">버튼</p>
                      <?php if ($buttons === []): ?>
                        <p class="fieldset-label">버튼이 없습니다.</p>
                      <?php else: ?>
                        <ul class="tpl-detail-buttons">
                          <?php foreach ($buttons as $button): ?>
                            <?php if (!is_array($button)) continue; ?>
                            <li><?= $this->e($button['name'] ?? '') ?> (<?= $this->e($button['linkTypeName'] ?? $button['linkType'] ?? '') ?>)
                              <?php foreach (['linkMo' => '모바일', 'linkPc' => 'PC', 'linkIos' => 'iOS', 'linkAnd' => 'Android'] as $linkField => $linkLabel): ?>
                                <?php if ((string) ($button[$linkField] ?? '') !== ''): ?><div style="overflow-wrap:anywhere"><?= $this->e($linkLabel) ?>: <?= $this->e($button[$linkField]) ?></div><?php endif ?>
                              <?php endforeach ?>
                            </li>
                          <?php endforeach ?>
                        </ul>
                      <?php endif ?>
                    </template>
                  </td>
                </tr>
              <?php endforeach; endif ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </section>
  <?php endforeach ?>
</section>
