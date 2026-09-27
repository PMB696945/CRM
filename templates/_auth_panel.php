<div class="auth-panel">
  <svg class="absolute top-0 right-0 w-72 opacity-40" viewBox="0 0 450 254" fill="none" aria-hidden="true"><g stroke="#465fff" stroke-opacity=".35"><?php for ($i = 0; $i <= 450; $i += 30): ?><path d="M<?= $i ?> 0V254"/><?php endfor; ?><?php for ($i = 0; $i <= 254; $i += 30): ?><path d="M0 <?= $i ?>H450"/><?php endfor; ?></g></svg>
  <svg class="absolute bottom-0 left-0 w-72 rotate-180 opacity-40" viewBox="0 0 450 254" fill="none" aria-hidden="true"><g stroke="#465fff" stroke-opacity=".35"><?php for ($i = 0; $i <= 450; $i += 30): ?><path d="M<?= $i ?> 0V254"/><?php endfor; ?><?php for ($i = 0; $i <= 254; $i += 30): ?><path d="M0 <?= $i ?>H450"/><?php endfor; ?></g></svg>
  <div class="relative z-1 flex max-w-xs flex-col items-center text-center">
    <span class="brand-mark mb-4 size-14! rounded-2xl"><?= icon('phone', 'size-8!') ?></span>
    <p class="text-2xl font-semibold text-white"><?= h(config('app_name')) ?></p>
    <p class="mt-2 text-gray-400">Customers, lines, contracts, support and sales for telecoms, in one place.</p>
  </div>
</div>
