<div class="breadcrumbs"><ul>
  <li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li>
  <li><a href="<?= $this->e($admin_url) ?>">쇼핑몰</a></li>
</ul></div>
<div class="page-head yc-admin-heading">
  <div><h1><?= $this->e($heading) ?></h1><?php if (($description ?? '') !== ''): ?><p class="muted"><?= $this->e($description) ?></p><?php endif ?></div>
  <?php if (($actions ?? []) !== []): ?><div class="row-actions"><?php foreach ($actions as $action): ?><?php $isPrimary = str_contains($action['url'], '/new'); ?><a class="btn btn-sm<?= $isPrimary ? ' btn-primary' : ' btn-outline' ?>" href="<?= $this->e($action['url']) ?>"><?php if ($isPrimary): ?><?= $this->icon('plus', 16) ?><?php endif ?><?= $this->e($action['label']) ?></a><?php endforeach ?></div><?php endif ?>
</div>
