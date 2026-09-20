<?php $v = static fn (string $key): string => (string) ($values['banner_' . $key] ?? ''); ?>
<section class="card" id="settings-banner"><div class="card-body">
  <h2 class="card-title">메인 배너</h2>
  <p class="muted">쇼핑몰 첫 화면의 큰 배너를 편집하세요. 저장하면 공개 화면에 반영됩니다.</p>
  <label class="label cursor-pointer"><input type="hidden" name="banner_use" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="banner_use" value="1"<?= $v('use') === '1' ? ' checked' : '' ?>> 메인 배너 표시</label>
  <label class="fieldset"><span class="fieldset-legend">상단 짧은 문구</span><input class="input input-bordered" name="banner_eyebrow" value="<?= $this->e($v('eyebrow')) ?>" maxlength="80"></label>
  <label class="fieldset"><span class="fieldset-legend">제목</span><textarea class="textarea textarea-bordered textarea-block" name="banner_title" rows="2" maxlength="200"><?= $this->e($v('title')) ?></textarea><span class="muted">줄바꿈을 사용할 수 있습니다. 배너를 표시할 때는 제목을 입력해 주세요.</span></label>
  <label class="fieldset"><span class="fieldset-legend">설명</span><textarea class="textarea textarea-bordered textarea-block" name="banner_description" rows="3" maxlength="1000"><?= $this->e($v('description')) ?></textarea></label>
  <div class="yc-banner-fields">
    <label class="fieldset"><span class="fieldset-legend">버튼 문구</span><input class="input input-bordered" name="banner_button_label" value="<?= $this->e($v('button_label')) ?>" maxlength="50"><span class="muted">비워 두면 버튼을 숨깁니다.</span></label>
    <label class="fieldset"><span class="fieldset-legend">버튼 이동 주소</span><input class="input input-bordered" name="banner_button_url" value="<?= $this->e($v('button_url')) ?>" maxlength="2000" placeholder="/shop/type?t=new"><span class="muted">비워 두면 진열 목록 또는 선택한 상품으로 이동합니다. /로 시작하는 사이트 내 경로나 https:// 주소를 입력하세요.</span></label>
  </div>
  <label class="fieldset"><span class="fieldset-legend">오른쪽 이미지 표시 방식</span><select class="select select-bordered" name="banner_mode" data-yc-banner-mode><?php foreach ($banner_modes as $mode => $label): ?><option value="<?= $this->e($mode) ?>"<?= $v('mode') === $mode ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select><span class="muted">자동 표시는 메인 진열의 첫 상품을 사용합니다. 랜덤 표시는 페이지를 열 때마다 대표 이미지가 있는 메인 진열 상품 중 하나를 고릅니다. 같은 상품이 다시 나올 수 있습니다.</span></label>
  <div data-yc-banner-panel="product">
    <label class="fieldset"><span class="fieldset-legend">표시할 상품 (특정 상품 선택 시)</span><select class="select select-bordered" name="banner_product_id">
      <option value="0">상품을 선택하세요</option>
      <?php if ((int) $v('product_id') > 0 && !in_array((int) $v('product_id'), array_map('intval', array_column($banner_products, 'id')), true)): ?><option value="<?= $this->e($v('product_id')) ?>" selected>기존 상품을 표시할 수 없습니다. 다시 선택하세요.</option><?php endif ?>
      <?php foreach ($banner_products as $product): ?><option value="<?= (int) $product['id'] ?>"<?= (int) $v('product_id') === (int) $product['id'] ? ' selected' : '' ?>><?= $this->e($product['name'] . ' (' . $product['code'] . ')') ?></option><?php endforeach ?>
    </select><span class="muted">대표 이미지가 있는 공개 상품만 선택할 수 있습니다. 상품명과 대표 이미지가 함께 표시됩니다.</span></label>
  </div>
  <div data-yc-banner-panel="upload">
    <label class="fieldset"><span class="fieldset-legend">배너 이미지 (직접 업로드 시)</span><input class="file-input file-input-bordered" type="file" name="banner_image" accept="image/jpeg,image/png,image/webp,image/gif"><span class="muted">JPG·PNG·WebP·GIF, 가로·세로 8,000px 이하. 용량은 사이트의 첨부 용량 설정을 따릅니다. 정사각형 이미지를 권장합니다.</span></label>
    <div class="yc-banner-fields">
      <label class="fieldset"><span class="fieldset-legend">이미지 대체 설명</span><input class="input input-bordered" name="banner_image_alt" value="<?= $this->e($v('image_alt')) ?>" maxlength="200"></label>
      <label class="fieldset"><span class="fieldset-legend">이미지 아래 문구</span><input class="input input-bordered" name="banner_image_caption" value="<?= $this->e($v('image_caption')) ?>" maxlength="200"></label>
    </div>
    <label class="fieldset"><span class="fieldset-legend">이미지 클릭 시 이동 주소</span><input class="input input-bordered" name="banner_image_url" value="<?= $this->e($v('image_url')) ?>" maxlength="2000" placeholder="/shop/type?t=discount"><span class="muted">비워 두면 이미지에 링크를 넣지 않습니다.</span></label>
  </div>
  <?php if ($banner_image_url !== ''): ?><div class="yc-banner-current"><img src="<?= $this->e($banner_image_url) ?>" alt="현재 저장된 배너 이미지" width="160" height="160"><label class="label cursor-pointer"><input class="checkbox checkbox-sm" type="checkbox" name="banner_image_delete" value="1"<?= ($values['banner_image_delete'] ?? '') === '1' ? ' checked' : '' ?>> 저장된 이미지 삭제</label><p class="muted">새 이미지 없이 삭제하면 자동 상품 표시로 전환됩니다. 새 파일을 선택하면 기존 이미지를 교체합니다.</p></div><?php endif ?>
</div></section>
