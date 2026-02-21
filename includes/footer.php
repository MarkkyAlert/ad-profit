<?php

declare(strict_types=1);

$shopCount = isset($shopCount) ? (int)$shopCount : 1;
$currentPage = $currentPage ?? '';
$navGridClass = $shopCount >= 2 ? 'grid-cols-5' : 'grid-cols-4';
?>
</main>

<nav class="fixed bottom-0 left-0 right-0 z-40 border-t border-white/[0.07] bg-[#0d1526]/95 px-2 py-2 backdrop-blur-md">
    <div class="mx-auto grid max-w-6xl <?= e($navGridClass) ?> gap-1 text-center text-[11px] text-slate-400 sm:text-xs">
        <?php
        $navLinks = [
            ['href' => '/dashboard.php', 'page' => 'dashboard',   'icon' => '📈', 'label' => 'แดชบอร์ด'],
            ['href' => '/add-record.php', 'page' => 'add-record',   'icon' => '➕', 'label' => 'บันทึก'],
            ['href' => '/history.php',   'page' => 'history',      'icon' => '📋', 'label' => 'ประวัติ'],
            ['href' => '/annual.php',    'page' => 'annual',       'icon' => '📅', 'label' => 'รายปี'],
        ];
        if ($shopCount >= 2) {
            $navLinks[] = ['href' => '/overview.php', 'page' => 'overview', 'icon' => '🏪', 'label' => 'รวมร้าน'];
        }
        foreach ($navLinks as $link):
            $isActive = $currentPage === $link['page'];
        ?>
            <a href="<?= e(app_url($link['href'])) ?>"
                class="flex flex-col items-center gap-0.5 rounded-xl px-1 py-2 transition-all duration-150 <?= $isActive ? 'nav-active' : 'hover:bg-white/5 hover:text-slate-300' ?>">
                <span class="text-base leading-none"><?= $link['icon'] ?></span>
                <span class="mt-0.5"><?= $link['label'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

<div id="global-loading" class="modal-bg fixed inset-0 z-[60] hidden items-center justify-center">
    <div class="section-card flex items-center gap-3 px-5 py-4 text-sm text-slate-300 shadow-2xl shadow-black/50">
        <span class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-indigo-500/40 border-t-indigo-500"></span>
        <span>กำลังประมวลผล...</span>
    </div>
</div>

<script>
    (function() {
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