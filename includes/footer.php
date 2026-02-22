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

<div id="global-confirm-modal" class="modal-bg fixed inset-0 z-[70] hidden items-center justify-center p-4 opacity-0 transition-opacity duration-200">
    <div id="global-confirm-card" class="section-card w-full max-w-sm transform scale-95 p-6 text-center shadow-2xl shadow-black/60 transition-transform duration-200">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-500/10 ring-1 ring-red-500/20">
            <svg class="h-7 w-7 text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
        </div>
        <h3 class="mb-2 text-lg font-bold text-slate-100">ยืนยันการดำเนินการ</h3>
        <p id="global-confirm-message" class="mb-6 text-sm text-slate-400">คุณแน่ใจหรือไม่ว่าต้องการดำเนินการนี้?</p>
        <div class="flex items-center justify-center gap-3">
            <button type="button" id="global-confirm-cancel" class="btn-ghost flex-1 py-2.5 text-sm">ยกเลิก</button>
            <button type="button" id="global-confirm-ok" class="btn-danger flex-1 py-2.5 text-sm">ยืนยัน</button>
        </div>
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

        const confirmModal = document.getElementById('global-confirm-modal');
        const confirmCard = document.getElementById('global-confirm-card');
        const confirmMessageEl = document.getElementById('global-confirm-message');
        const btnConfirmCancel = document.getElementById('global-confirm-cancel');
        const btnConfirmOk = document.getElementById('global-confirm-ok');
        let pendingForm = null;

        const showConfirmModal = (message) => {
            if (confirmMessageEl) confirmMessageEl.textContent = message;
            if (confirmModal) {
                confirmModal.classList.remove('hidden');
                confirmModal.classList.add('flex');
                requestAnimationFrame(() => {
                    confirmModal.classList.remove('opacity-0');
                    if (confirmCard) {
                        confirmCard.classList.remove('scale-95');
                        confirmCard.classList.add('scale-100');
                    }
                });
            }
        };

        const hideConfirmModal = () => {
            if (confirmModal) {
                confirmModal.classList.add('opacity-0');
                if (confirmCard) {
                    confirmCard.classList.remove('scale-100');
                    confirmCard.classList.add('scale-95');
                }
                setTimeout(() => {
                    confirmModal.classList.add('hidden');
                    confirmModal.classList.remove('flex');
                    pendingForm = null;
                }, 200);
            }
        };

        if (btnConfirmCancel) {
            btnConfirmCancel.addEventListener('click', hideConfirmModal);
        }

        if (confirmModal) {
            confirmModal.addEventListener('click', (event) => {
                if (event.target === confirmModal) {
                    hideConfirmModal();
                }
            });
        }

        if (btnConfirmOk) {
            btnConfirmOk.addEventListener('click', () => {
                if (pendingForm) {
                    const formToSubmit = pendingForm;
                    hideConfirmModal();
                    if (!formToSubmit.hasAttribute('data-no-loading')) {
                        showLoading();
                    }
                    formToSubmit.submit();
                }
            });
        }

        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (event.defaultPrevented) {
                    return;
                }

                const confirmMessage = form.getAttribute('data-confirm');
                if (confirmMessage && pendingForm !== form) {
                    event.preventDefault();
                    pendingForm = form;
                    showConfirmModal(confirmMessage);
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