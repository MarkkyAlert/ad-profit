<?php

declare(strict_types=1);

$shopCount = isset($shopCount) ? (int)$shopCount : 1;
$currentPage = $currentPage ?? '';
$navGridClass = $shopCount >= 2 ? 'grid-cols-5' : 'grid-cols-4';
?>
</main>

<nav class="fixed bottom-0 left-0 right-0 z-40 border-t border-slate-700 bg-slate-950/95 px-2 py-2 backdrop-blur">
    <div class="mx-auto grid max-w-6xl <?= e($navGridClass) ?> gap-1 text-center text-[11px] text-slate-300 sm:text-xs">
        <a href="<?= e(app_url('/dashboard.php')) ?>" class="rounded-lg px-2 py-2 <?= $currentPage === 'dashboard' ? 'bg-slate-700 text-white' : 'hover:bg-slate-800' ?>">📈 แดชบอร์ด</a>
        <a href="<?= e(app_url('/add-record.php')) ?>" class="rounded-lg px-2 py-2 <?= $currentPage === 'add-record' ? 'bg-slate-700 text-white' : 'hover:bg-slate-800' ?>">➕ บันทึก</a>
        <a href="<?= e(app_url('/history.php')) ?>" class="rounded-lg px-2 py-2 <?= $currentPage === 'history' ? 'bg-slate-700 text-white' : 'hover:bg-slate-800' ?>">📋 ประวัติ</a>
        <a href="<?= e(app_url('/annual.php')) ?>" class="rounded-lg px-2 py-2 <?= $currentPage === 'annual' ? 'bg-slate-700 text-white' : 'hover:bg-slate-800' ?>">📅 รายปี</a>
        <?php if ($shopCount >= 2): ?>
            <a href="<?= e(app_url('/overview.php')) ?>" class="rounded-lg px-2 py-2 <?= $currentPage === 'overview' ? 'bg-slate-700 text-white' : 'hover:bg-slate-800' ?>">🏪 รวมร้าน</a>
        <?php endif; ?>
    </div>
</nav>

<div id="global-loading" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/65">
    <div class="flex items-center gap-3 rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-200 shadow-xl">
        <span class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-slate-500 border-t-cyan-400"></span>
        <span>กำลังประมวลผล...</span>
    </div>
</div>

<script>
    (function () {
        const toast = document.getElementById('app-toast');
        if (toast) {
            setTimeout(() => {
                toast.classList.add('opacity-0', 'transition', 'duration-300');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        const loading = document.getElementById('global-loading');
        const showLoading = () => {
            if (!loading) {
                return;
            }

            loading.classList.remove('hidden');
            loading.classList.add('flex');
        };

        const hideLoading = () => {
            if (!loading) {
                return;
            }

            loading.classList.add('hidden');
            loading.classList.remove('flex');
        };

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (event.defaultPrevented) {
                    return;
                }

                const confirmMessage = form.getAttribute('data-confirm');
                if (confirmMessage && !window.confirm(confirmMessage)) {
                    event.preventDefault();
                    return;
                }

                if (form.hasAttribute('data-no-loading')) {
                    return;
                }

                showLoading();
            });
        });

        document.querySelectorAll('a[data-loading-link="true"]').forEach((link) => {
            link.addEventListener('click', (event) => {
                if (
                    event.defaultPrevented ||
                    event.button !== 0 ||
                    event.metaKey ||
                    event.ctrlKey ||
                    event.shiftKey ||
                    event.altKey
                ) {
                    return;
                }

                showLoading();
                window.setTimeout(hideLoading, 2500);
            });
        });
    })();
</script>
</body>
</html>
