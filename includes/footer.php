<?php

declare(strict_types=1);

$shopCount = isset($shopCount) ? (int)$shopCount : 1;
$currentPage = $currentPage ?? '';
$navGridClass = $shopCount >= 2 ? 'grid-cols-5' : 'grid-cols-4';
// ⚠️ ยังไม่ได้ล็อกอิน = ไม่ต้องมีเมนูล่าง ทุกปุ่มพากลับหน้าเข้าสู่ระบบอยู่ดี
// (ตั้งค่าไว้ที่ `includes/header.php` ซึ่ง include ก่อนเสมอ — ตัวแปรอยู่ scope เดียวกัน)
$isSignedIn = $isSignedIn ?? (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0);
?>
</main>

<?php if ($isSignedIn): ?>
<nav class="fixed bottom-0 left-0 right-0 z-40 border-t border-white/[0.07] bg-[#0d1526]/95 px-1 pb-[env(safe-area-inset-bottom)] backdrop-blur-md">
    <div class="mx-auto grid max-w-6xl <?= e($navGridClass) ?> gap-0.5 py-1.5 text-center text-[10px] text-slate-400 sm:gap-1 sm:py-2 sm:text-xs">
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
                class="flex flex-col items-center gap-0.5 rounded-xl px-1 py-2.5 transition-all duration-150 <?= $isActive ? 'nav-active' : 'hover:bg-white/5 hover:text-slate-300' ?>">
                <span class="text-base leading-none"><?= $link['icon'] ?></span>
                <span class="mt-0.5 leading-tight"><?= $link['label'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<?php endif; ?>

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
        <div id="global-confirm-typed" class="mb-6 hidden text-left">
            <p id="global-confirm-typed-prompt" class="mb-2 text-xs text-slate-400"></p>
            <div id="global-confirm-typed-expected" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm font-mono text-slate-200 break-all"></div>
            <?php /* ⚠️ ต้องมีชื่อให้โปรแกรมอ่านหน้าจอประกาศ — เดิมเป็นตัวควบคุมเดียวในทั้งระบบ
                     ที่ไม่มีชื่อเลย (จาก 28 ตัวที่วัด) และมันคือด่านสุดท้ายของการกระทำที่ทำลายข้อมูล
                     มากที่สุด (ลบร้านทั้งร้าน) · คนที่ใช้โปรแกรมอ่านหน้าจอจะได้ยินแค่ "ช่องกรอกข้อความ"
                     โดยไม่รู้ว่าต้องพิมพ์อะไร
                     ⚠️ ผูกกับข้อความอธิบายที่มีอยู่แล้ว (`…-typed-prompt`) แทนการเขียนคำใหม่ —
                     ข้อความนั้นเปลี่ยนตามสิ่งที่กำลังลบ (เช่นบอกว่าให้พิมพ์กี่ตัวแรกของชื่อร้าน)
                     ถ้าเขียนคำตายตัวไว้ตรงนี้ วันหนึ่งสองที่จะพูดไม่ตรงกัน
                     · `aria-label` เป็นตัวสำรองสำหรับตอนที่ยังไม่มีข้อความอธิบาย */ ?>
            <input
                id="global-confirm-typed-input"
                type="text"
                autocomplete="off"
                spellcheck="false"
                aria-label="พิมพ์ข้อความยืนยันให้ตรงกับด้านบน"
                aria-labelledby="global-confirm-typed-prompt"
                aria-describedby="global-confirm-typed-error"
                class="mt-3 w-full rounded-xl border border-white/10 bg-[#070c18] px-3 py-2 text-sm text-slate-200"
                placeholder="พิมพ์ข้อความด้านบนให้ตรง">
            <p id="global-confirm-typed-error" class="mt-2 hidden text-xs text-red-300">ข้อความที่พิมพ์ไม่ตรงกัน</p>
        </div>
        <div class="flex items-center justify-center gap-3">
            <button type="button" id="global-confirm-cancel" class="btn-ghost flex-1 py-2.5 text-sm">ยกเลิก</button>
            <button type="button" id="global-confirm-ok" class="btn-danger flex-1 py-2.5 text-sm">ยืนยัน</button>
        </div>
    </div>
</div>

<script>
    (function() {
        /* ⚠️⚠️ แถบแจ้งผลเดิมหายใน 3 วินาที ซึ่งสั้นเกินไปในหน้าที่มีคำเตือนถาวรอยู่ด้วย
           · วัดจริงที่หน้าบันทึก: กดบันทึกสำเร็จ → เห็นแถบเขียว "บันทึกข้อมูลเรียบร้อยแล้ว"
             แวบหนึ่ง → 3 วินาทีต่อมาเหลือแต่แถบเหลืองถาวรว่า "วันที่นี้มีข้อมูลอยู่แล้ว
             กดบันทึกจะเป็นการแก้ไขทับ" · คนที่ละสายตาไปหยิบใบเสร็จใบถัดไป กลับมาเห็นแต่
             คำเตือน ไม่เห็นคำยืนยัน แล้วอาจกดซ้ำเพราะไม่แน่ใจว่าสำเร็จหรือยัง
           · ข้อความผิดพลาดอยู่นานกว่า เพราะต้องใช้เวลาอ่านและมักต้องทำอะไรต่อ
           · แตะที่แถบเพื่อปิดเองได้ (บางคนอยากให้พ้นทางเลย) */
        const toast = document.getElementById('app-toast');
        if (toast) {
            const dismissToast = () => {
                if (!toast.isConnected) {
                    return;
                }

                toast.classList.add('opacity-0', 'transition', 'duration-300');
                setTimeout(() => toast.remove(), 300);
            };

            const isError = toast.getAttribute('data-toast-kind') === 'error';
            setTimeout(dismissToast, isError ? 10000 : 6000);
            toast.addEventListener('click', dismissToast);
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
        const confirmTypedSection = document.getElementById('global-confirm-typed');
        const confirmTypedPromptEl = document.getElementById('global-confirm-typed-prompt');
        const confirmTypedExpectedEl = document.getElementById('global-confirm-typed-expected');
        const confirmTypedInputEl = document.getElementById('global-confirm-typed-input');
        const confirmTypedErrorEl = document.getElementById('global-confirm-typed-error');
        const btnConfirmCancel = document.getElementById('global-confirm-cancel');
        const btnConfirmOk = document.getElementById('global-confirm-ok');
        let pendingForm = null;

        const normalizeText = (value) => (value || '').toString().trim();

        const resetTypedConfirm = () => {
            if (confirmTypedPromptEl) confirmTypedPromptEl.textContent = '';
            if (confirmTypedExpectedEl) confirmTypedExpectedEl.textContent = '';
            if (confirmTypedInputEl) confirmTypedInputEl.value = '';
            if (confirmTypedErrorEl) confirmTypedErrorEl.classList.add('hidden');
            if (confirmTypedSection) confirmTypedSection.classList.add('hidden');
        };

        const configureTypedConfirm = (form) => {
            const expected = normalizeText(form?.getAttribute('data-confirm-typed-expected'));
            if (expected === '') {
                resetTypedConfirm();
                return;
            }

            const promptText = normalizeText(form?.getAttribute('data-confirm-typed-prompt'))
                || 'เพื่อยืนยัน กรุณาพิมพ์ข้อความให้ตรงตามนี้:';

            if (confirmTypedPromptEl) confirmTypedPromptEl.textContent = promptText;
            if (confirmTypedExpectedEl) confirmTypedExpectedEl.textContent = expected;
            if (confirmTypedInputEl) confirmTypedInputEl.value = '';
            if (confirmTypedErrorEl) confirmTypedErrorEl.classList.add('hidden');
            if (confirmTypedSection) confirmTypedSection.classList.remove('hidden');
        };

        const markFormSubmitted = (form) => {
            if (!form) {
                return false;
            }

            if (normalizeText(form.getAttribute('data-submitted')) === '1') {
                return false;
            }

            form.setAttribute('data-submitted', '1');

            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.classList.add('opacity-70', 'cursor-not-allowed');
            });

            return true;
        };

        const showConfirmModal = (message) => {
            if (confirmMessageEl) confirmMessageEl.textContent = message;
            configureTypedConfirm(pendingForm);
            if (confirmModal) {
                confirmModal.classList.remove('hidden');
                confirmModal.classList.add('flex');
                requestAnimationFrame(() => {
                    confirmModal.classList.remove('opacity-0');
                    if (confirmCard) {
                        confirmCard.classList.remove('scale-95');
                        confirmCard.classList.add('scale-100');
                    }

                    const expected = normalizeText(pendingForm?.getAttribute('data-confirm-typed-expected'));
                    if (expected !== '' && confirmTypedInputEl) {
                        confirmTypedInputEl.focus();
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
                    resetTypedConfirm();
                }, 200);
            }
        };

        if (confirmTypedInputEl) {
            confirmTypedInputEl.addEventListener('input', () => {
                if (confirmTypedErrorEl) confirmTypedErrorEl.classList.add('hidden');
            });
        }

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

                    const typedExpected = normalizeText(formToSubmit.getAttribute('data-confirm-typed-expected'));
                    if (typedExpected !== '') {
                        const typed = normalizeText(confirmTypedInputEl ? confirmTypedInputEl.value : '');
                        if (typed !== typedExpected) {
                            if (confirmTypedErrorEl) {
                                confirmTypedErrorEl.classList.remove('hidden');
                            } else {
                                window.alert('ข้อความที่พิมพ์ไม่ตรงกัน ระบบยังไม่ดำเนินการ');
                            }

                            if (confirmTypedInputEl) {
                                confirmTypedInputEl.focus();
                            }
                            return;
                        }

                        const typedInputName = normalizeText(formToSubmit.getAttribute('data-confirm-typed-input'));
                        const hiddenInput = typedInputName !== ''
                            ? formToSubmit.querySelector(`input[name="${typedInputName}"]`)
                            : formToSubmit.querySelector('input[name="confirm_shop_name"]');

                        if (hiddenInput) {
                            hiddenInput.value = typed;
                        }
                    }

                    if (!markFormSubmitted(formToSubmit)) {
                        hideConfirmModal();
                        return;
                    }

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

                if (normalizeText(form.getAttribute('data-submitted')) === '1') {
                    event.preventDefault();
                    return;
                }

                const confirmMessage = form.getAttribute('data-confirm');
                if (confirmMessage) {
                    // Ensure the form cannot be submitted while the confirm modal is open
                    // (avoid double-click bypass).
                    event.preventDefault();
                    if (pendingForm === form) {
                        return;
                    }

                    pendingForm = form;
                    showConfirmModal(confirmMessage);
                    return;
                }

                markFormSubmitted(form);

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

        /* ⭐⭐ แปะชื่อคอลัมน์ให้ทุกช่อง เพื่อให้ตารางกลายเป็นการ์ดอ่านรู้เรื่องบนมือถือ
           (หน้าตาการ์ดอยู่ใน CSS `.table-cards` ที่ `includes/header.php`)

           ⚠️ อ่านชื่อจาก <thead> ของตารางนั้นเอง **ไม่ใช่พิมพ์รายชื่อไว้ในสคริปต์** —
           โปรเจกต์มีตาราง 11 ตัว ถ้าพิมพ์ไว้ วันที่ใครเพิ่ม/สลับคอลัมน์ ป้ายจะชี้ผิดช่อง
           เงียบ ๆ (บทเรียนเดิมทั้งไฟล์ CLAUDE.md: เขียนกติกาซ้ำ = จุดที่พังแน่นอน)

           ⚠️ ช่องที่ฝั่ง PHP ใส่ `data-label` มาแล้วจะไม่ถูกทับ — หน้าที่สำคัญที่สุด
           (ประวัติรายการ) เขียนป้ายไว้ในเทมเพลตเอง จะได้อ่านรู้เรื่องแม้สคริปต์ไม่ทำงาน */
        const labelTableCardCells = (table) => {
            const headerRow = table.tHead ? table.tHead.rows[0] : null;
            if (!headerRow) {
                return;
            }

            // ⚠️⚠️ กาง <th> ที่กินหลายคอลัมน์ออกก่อน แล้วเดินด้วย "เลขคอลัมน์จริง"
            // ไม่ใช่ลำดับของช่องในแถว — วัดจริงตอนใช้ลำดับช่อง: แถว "รวมทุกร้าน"
            // ของหน้ารวมร้าน (ช่องแรกกิน 2 คอลัมน์) ป้ายเลื่อนไปทั้งแถว จนยอดขาย
            // ถูกเรียกว่า "ร้าน" และ ROAS ถูกเรียกว่า "เทียบเดือนก่อน"
            const headings = [];
            Array.from(headerRow.cells).forEach((cell) => {
                const text = cell.textContent.trim();
                for (let span = 0; span < (cell.colSpan || 1); span += 1) {
                    headings.push(text);
                }
            });

            Array.from(table.tBodies).concat(table.tFoot ? [table.tFoot] : []).forEach((section) => {
                Array.from(section.rows).forEach((row) => {
                    let column = 0;

                    Array.from(row.cells).forEach((cell) => {
                        const span = cell.colSpan || 1;
                        const heading = headings[column] || '';
                        column += span;

                        // ช่องที่กินหลายคอลัมน์ (เช่น "ยังไม่มีข้อมูล") ไม่ใช่ค่าของคอลัมน์ไหน
                        if (span > 1) {
                            return;
                        }

                        if (!cell.hasAttribute('data-label') && heading !== '') {
                            cell.setAttribute('data-label', heading);
                        }

                        // กำไรคือเหตุผลที่แอปนี้มีอยู่ — บนการ์ดต้องเด่นกว่าช่องอื่น
                        if (heading === 'กำไร' && !cell.hasAttribute('data-emphasis')) {
                            cell.setAttribute('data-emphasis', 'profit');
                        }
                    });
                });
            });
        };

        document.querySelectorAll('table.table-cards').forEach(labelTableCardCells);
    })();
</script>
</body>

</html>