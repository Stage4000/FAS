<form class="saved-search-notify-form border rounded-3 p-3 mb-3" data-saved-search-notify method="post" action="/api/saved-search.php">
<p class="saved-search-notify-unavailable small" role="status">Stock notifications are unavailable until this form finishes loading. If it stays unavailable, please use <a href="/contact">Contact Us</a>.</p>
<fieldset data-saved-search-fields disabled>
<div class="small fw-semibold mb-2"><i class="fas fa-bell text-danger me-1"></i>Notify me when similar parts arrive</div>
<div class="row g-2 align-items-start">
<div class="col-lg">
<label for="saved-search-notify-email" class="visually-hidden">Email address</label>
<input id="saved-search-notify-email" type="email" class="form-control form-control-sm" name="email" placeholder="Email address" autocomplete="email" required>
</div>
<div class="col-lg-auto">
<button class="btn btn-danger btn-sm w-100" type="submit">Notify Me</button>
</div>
</div>
<label class="form-check small text-muted mt-2 mb-0">
<input class="form-check-input" type="checkbox" name="consent" value="1" required>
I agree to be contacted only about matching Flip and Strip inventory.
</label>
</fieldset>
<div class="saved-search-notify-status small mt-2" aria-live="polite"></div>
</form>
