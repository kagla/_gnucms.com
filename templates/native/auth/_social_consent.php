<?php
// 소셜 가입은 체크박스를 받을 자리가 없다. 무엇에 동의하게 되는지 여기서 밝히고,
// 실제 동의 기록은 LinkingService 가 가입 시점에 남긴다. 물어보지 않은 선택 항목은
// 동의로 볼 수 없으니 여기에도 적지 않는다. 테마마다 두지 않고 default 로 폴백시킨다.
$required_consents = array_values(array_filter($consent_documents, fn ($d) => (int) $d['required'] === 1));
?>
<?php if ($required_consents !== []): ?>
  <p class="social-consent">계속하면 <?php $n = count($required_consents); $i = 0; foreach ($required_consents as $doc): $i++ ?><a class="link" href="<?= $this->url('terms.show', ['slug' => $doc['slug']]) ?>" target="_blank" rel="noopener"><?= $this->e($doc['title']) ?></a><?= $i === $n ? '' : ', ' ?><?php endforeach ?>에 동의한 것으로 봅니다.</p>
<?php endif ?>
