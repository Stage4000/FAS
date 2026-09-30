<?php if ($landingIntroCopy !== ''): ?>
<div class="card border-0 shadow-sm mb-4 products-landing-intro" data-theme-card>
<div class="card-body p-4">
<div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
<div>
<div class="small text-danger fw-semibold text-uppercase mb-1">Parts buying guide</div>
<h2 class="h5 fw-bold mb-2"><?php echo htmlspecialchars($currentCategoryName); ?></h2>
<p class="text-muted mb-0"><?php echo htmlspecialchars($landingIntroCopy); ?></p>
</div>
<div class="products-landing-links d-flex flex-wrap gap-2 align-content-start">
<?php foreach ($landingIntroLinks as $linkUrl => $linkLabel): ?>
<a href="<?php echo htmlspecialchars($linkUrl); ?>" class="btn btn-outline-danger btn-sm"><?php echo htmlspecialchars($linkLabel); ?></a>
<?php endforeach; ?>
</div>
</div>
</div>
</div>
<?php endif; ?>

