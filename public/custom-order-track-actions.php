<?php
/* ════ custom-order-track-actions.php — بلوک کامل یک سفارش (Accordion body) ════ */

/**
 * از custom-order-track.php با require صدا زده می‌شود.
 * ورودی: $fullMo (سفارش کامل از co_load_order_full) یا $order
 * متغیرهای در دسترس: $stages, $stageKeys, $h, $coStatusInfo, $mobile, $errors, $code
 */

$order = $fullMo ?? $order ?? null;
if ($order === null) return;

$status      = (string)$order['status'];
$isRejected  = $status === 'rejected';
$isCancelled = $status === 'cancelled';
$stageIndex  = array_search($status, $stageKeys, true);
$totalQty    = array_sum(array_map(static fn($it) => (int)$it['qty'], $order['items']));
[$curLabel, $curTone] = $coStatusInfo($status);
$documentsSubmitted = !empty($order['id_card_front_path']) && !empty($order['id_card_back_path']);
$totalAmt    = (float)($order['total_amount'] ?? 0);
$downPercent = (float)($order['down_payment_percent'] ?? 0);
$downAmt     = (float)($order['down_payment_amount'] ?? 0);
$setAmt      = (float)($order['settlement_amount'] ?? 0);
?>

<!-- ▂▂ خلاصه سفارش ▂▂ -->
<div class="coi-summary">
    <div class="coi-summary-head">
        <div>
            <b class="coi-track"><?= $h($order['tracking_code']) ?></b>
            <span class="coi-chip <?= $isRejected || $isCancelled ? 'bad' : '' ?>">
                <?= $h(co_stage_label($status)) ?>
            </span>
            <?php if ($curTone === 'todo'): ?>
                <span class="coi-chip todo">⬇ اقدام بعدی با شماست</span>
            <?php endif; ?>
        </div>
        <span class="coi-summary-date">ثبت: <?= $h(co_fa_date($order['created_at'])) ?></span>
    </div>

    <?php if (!empty($order['ref_product_name'])): ?>
        <p class="coi-ref-line">🧦 سفارشی‌سازی بر اساس محصول: <b><?= $h($order['ref_product_name']) ?></b>
            (کد <?= $h($order['ref_product_code']) ?>)</p>
    <?php endif; ?>
    <?php if (!empty($order['logo_path'])): ?>
        <p class="coi-ref-line">🎨 طرح/لوگوی ارسالی شما: <a href="<?= $h(private_file_url($order['logo_path'])) ?>" target="_blank" rel="noopener">مشاهده فایل ↗</a></p>
    <?php endif; ?>

    <table class="coi-items">
        <tr>
            <th>نوع</th>
            <th>رنگ / طرح</th>
            <th>تعداد (جفت)</th>
            <th>توضیح</th>
        </tr>
        <?php foreach ($order['items'] as $it): ?>
            <tr>
                <td><?= $h($it['product_type']) ?></td>
                <td><?= $h($it['color'] ?: '—') ?></td>
                <td><?= $h(number_format((int)$it['qty'])) ?></td>
                <td><?= $h($it['notes'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="coi-items-total">
            <td colspan="2">جمع کل</td>
            <td colspan="2"><?= $h(number_format($totalQty)) ?> جفت</td>
        </tr>
    </table>
</div>

<!-- ▂▂ تایم‌لاین ▂▂ -->
<?php if (!$isRejected && !$isCancelled): ?>
    <div class="coi-timeline">
        <?php foreach ($stageKeys as $i => $key): ?>
            <?php
            $state = 'pending';
            if ($stageIndex !== false && $i < $stageIndex)       $state = 'done';
            elseif ($stageIndex !== false && $i === $stageIndex) $state = 'current';
            ?>
            <div class="coi-tl-step <?= $state ?>">
                <span class="coi-tl-dot"></span>
                <div class="coi-tl-body">
                    <b><?= ($i + 1) ?>. <?= $h($stages[$key][0]) ?></b>
                    <p><?= $h($stages[$key][1]) ?></p>
                    <?php
                    foreach ($order['events'] as $ev) {
                        if ($ev['stage_key'] === $key) {
                            echo '<small>' . $h(co_fa_date($ev['created_at'])) . ($ev['note'] ? ' — ' . $h($ev['note']) : '') . '</small>';
                        }
                    }
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="form-alert">این سفارش <?= $isRejected ? 'رد شده' : 'لغو شده' ?> است.
        <?php foreach ($order['events'] as $ev): ?>
            <?php if (in_array($ev['stage_key'], ['rejected', 'cancelled'], true) && $ev['note']): ?>
                <br><small>دلیل: <?= $h($ev['note']) ?></small>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت پیش‌فاکتور ▂▂ -->
<?php if (!empty($order['proforma_number']) && in_array($status, ['admin_review', 'down_payment', 'sample_production', 'sample_sent', 'sample_approval', 'settlement', 'production', 'quality_control', 'shipped', 'delivered'], true)): ?>
    <div class="coi-action-box">
        <h2>🧾 پیش‌فاکتور</h2>
        <p>
            <b>شماره پیش‌فاکتور:</b> <span dir="ltr"><?= $h($order['proforma_number']) ?></span>
            &nbsp;|&nbsp;
            <b>تاریخ:</b> <?= $h(co_fa_date($order['proforma_issued_at'] ?? null)) ?>
        </p>
        <p><b>مبلغ کل کار:</b> <?= $totalAmt > 0 ? number_format($totalAmt) . ' تومان' : '—' ?></p>
        <p><b>سهم پیش‌پرداخت (<?= $downPercent > 0 ? $h($downPercent) . '٪' : '—' ?>):</b>
            <?= $downAmt > 0 ? number_format($downAmt) . ' تومان' : '—' ?></p>
        <?php if ($setAmt > 0): ?>
            <p><b>مابه‌التفاوت (تسویه):</b> <?= number_format($setAmt) ?> تومان</p>
        <?php endif; ?>
        <?php if (!empty($order['admin_note'])): ?>
            <p><b>یادداشت ادمین:</b> <?= nl2br($h($order['admin_note'])) ?></p>
        <?php endif; ?>
        <p style="margin-top:12px;">
            <a class="btn btn-ghost" target="_blank" rel="noopener"
                href="/custom-invoice-print.php?id=<?= (int)$order['id'] ?>&type=quotation&code=<?= urlencode((string)$order['tracking_code']) ?><?= $mobile !== '' ? '&mobile=' . urlencode($mobile) : '' ?>">
                🖨 مشاهده / چاپ پیش‌فاکتور
            </a>
        </p>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت آپلود رسید پیش‌پرداخت ▂▂ -->
<?php if ($status === 'down_payment'): ?>
    <div class="coi-action-box">
        <h2>💳 پیش‌پرداخت (<?= $downPercent > 0 ? $h($downPercent) . '٪' : '۳۰٪' ?>)</h2>
        <?php if ($downAmt > 0): ?>
            <p>مبلغ پیش‌پرداخت: <b><?= number_format($downAmt) ?> تومان</b> (از کل <?= $totalAmt > 0 ? number_format($totalAmt) : '—' ?> تومان)</p>
        <?php else: ?>
            <p>مبلغ پیش‌پرداخت: <b>به‌زودی توسط ادمین اعلام می‌شود</b></p>
        <?php endif; ?>
        <p class="field-hint">شماره کارت/حساب توسط کارشناس به شما پیامک یا در واتساپ اعلام می‌شود.
            پس از واریز، تصویر رسید را همین‌جا بارگذاری کنید تا بررسی و تایید شود.</p>
        <?php if (!empty($order['down_payment_receipt_path'])): ?>
            <p class="field-hint">✔ رسید شما ثبت شده — <a href="<?= $h(private_file_url($order['down_payment_receipt_path'])) ?>" target="_blank">مشاهده</a> — در انتظار تایید کارشناس</p>
        <?php else: ?>
            <form method="post" action="" enctype="multipart/form-data" novalidate>
                <?= co_csrf_field() ?>
                <input type="hidden" name="action" value="receipt">
                <input type="hidden" name="code" value="<?= $h($order['tracking_code']) ?>">
                <input type="hidden" name="mobile" value="<?= $h($mobile) ?>">
                <div class="form-field">
                    <label>تصویر رسید واریز <i>*</i></label>
                    <div class="co-drop">
                        <input type="file" name="payment_receipt" id="coFileReceipt-<?= (int)$order['id'] ?>" class="co-file-input"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <label class="co-dropzone" for="coFileReceipt-<?= (int)$order['id'] ?>">
                            <span class="co-drop-ico" aria-hidden="true">🧾</span>
                            <b>تصویر رسید واریز را اینجا رها کنید</b>
                            <span class="co-drop-or">یا کلیک کنید و انتخاب کنید</span>
                            <small>JPG / PNG / WebP — حداکثر ۴ مگابایت</small>
                        </label>
                        <div class="co-file-chip" hidden>
                            <span class="co-chip-ico" aria-hidden="true">📄</span>
                            <span class="co-chip-name" dir="ltr"></span>
                            <span class="co-chip-size"></span>
                            <button type="button" class="co-chip-clear" aria-label="حذف فایل" title="حذف فایل">×</button>
                        </div>
                        <div class="co-file-error" hidden></div>
                    </div>
                    <?php if (isset($errors['payment_receipt'])): ?><span class="field-error"><?= $h($errors['payment_receipt']) ?></span><?php endif; ?>
                </div>
                <button type="submit" class="btn btn-orange co-submit">ارسال رسید ←</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت اطلاعات نمونه (sample_sent / sample_approval) ▂▂ -->
<?php if (in_array($status, ['sample_sent', 'sample_approval'], true)): ?>
    <div class="coi-action-box">
        <h2>🔬 نمونه کار</h2>
        <?php if (!empty($order['sample_carrier']) || !empty($order['sample_tracking_code'])): ?>
            <p><b>شرکت حمل:</b> <?= $h($order['sample_carrier'] ?? '—') ?>
                &nbsp;|&nbsp;
                <b>کد رهگیری:</b> <span dir="ltr"><?= $h($order['sample_tracking_code'] ?? '—') ?></span>
            </p>
            <p><b>ارسال در:</b> <?= $h(co_fa_date($order['sample_sent_at'] ?? null)) ?></p>
        <?php endif; ?>

        <?php if ($status === 'sample_sent'): ?>
            <p class="field-hint">نمونه را دریافت و بررسی کنید. اگر مورد تایید است، روی «تایید نمونه» بزنید تا
                کارشناس دستور تسویه صادر کند. اگر نیاز به اصلاح دارید، «درخواست اصلاح» را بزنید و
                اصلاحات را توضیح دهید.</p>

            <form method="post" action="" novalidate>
                <?= co_csrf_field() ?>
                <input type="hidden" name="action" value="approve_sample">
                <input type="hidden" name="code" value="<?= $h($order['tracking_code']) ?>">
                <input type="hidden" name="mobile" value="<?= $h($mobile) ?>">
                <button type="submit" class="btn btn-orange co-submit"
                    onclick="return confirm('نمونه را تایید می‌کنید؟ پس از تایید، دستور تسویه صادر می‌شود.');">
                    ✅ تایید نمونه ←
                </button>
            </form>

            <form method="post" action="" novalidate style="margin-top:16px;">
                <?= co_csrf_field() ?>
                <input type="hidden" name="action" value="revise_sample">
                <input type="hidden" name="code" value="<?= $h($order['tracking_code']) ?>">
                <input type="hidden" name="mobile" value="<?= $h($mobile) ?>">
                <div class="form-field">
                    <label>توضیح اصلاحات مورد نظر <i>*</i></label>
                    <textarea name="revision_note" rows="4"
                        placeholder="مثلاً: رنگ سرمه‌ای تیره‌تر، طول ساق ۲ سانت بیشتر، لوگو کمی کوچک‌تر…"><?= $h($_POST['revision_note'] ?? '') ?></textarea>
                    <?php if (isset($errors['revision_note'])): ?><span class="field-error"><?= $h($errors['revision_note']) ?></span><?php endif; ?>
                </div>
                <button type="submit" class="btn btn-ghost">🔄 درخواست اصلاح نمونه</button>
            </form>

        <?php else: /* sample_approval — تایید شده، منتظر ادمین */ ?>
            <div class="form-alert ok" style="background:#f0fdf4;border-color:#86efac;color:#166534;">
                ✅ شما نمونه را تایید کردید (<?= $h(co_fa_date($order['sample_approved_at'] ?? null)) ?>).
                منتظر صدور دستور تسویه توسط کارشناس هستید.
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت آپلود رسید تسویه ▂▂ -->
<?php if ($status === 'settlement'): ?>
    <div class="coi-action-box">
        <h2>💳 تسویه مابه‌التفاوت</h2>
        <?php if ($setAmt > 0): ?>
            <p>مبلغ تسویه: <b><?= number_format($setAmt) ?> تومان</b>
                (مابه‌التفاوت مبلغ کل و پیش‌پرداخت)</p>
        <?php else: ?>
            <p>مبلغ تسویه: <b>به‌زودی توسط کارشناس اعلام می‌شود</b></p>
        <?php endif; ?>
        <p class="field-hint">پس از واریز مابه‌التفاوت، تصویر رسید را بارگذاری کنید تا تایید شود و
            تولید انبوه آغاز گردد.</p>
        <?php if (!empty($order['settlement_receipt_path'])): ?>
            <p class="field-hint">✔ رسید شما ثبت شده — <a href="<?= $h(private_file_url($order['settlement_receipt_path'])) ?>" target="_blank">مشاهده</a> — در انتظار تایید کارشناس</p>
        <?php else: ?>
            <form method="post" action="" enctype="multipart/form-data" novalidate>
                <?= co_csrf_field() ?>
                <input type="hidden" name="action" value="settlement_receipt">
                <input type="hidden" name="code" value="<?= $h($order['tracking_code']) ?>">
                <input type="hidden" name="mobile" value="<?= $h($mobile) ?>">
                <div class="form-field">
                    <label>تصویر رسید تسویه <i>*</i></label>
                    <div class="co-drop">
                        <input type="file" name="settlement_receipt" id="coFileSettlement-<?= (int)$order['id'] ?>" class="co-file-input"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <label class="co-dropzone" for="coFileSettlement-<?= (int)$order['id'] ?>">
                            <span class="co-drop-ico" aria-hidden="true">🧾</span>
                            <b>تصویر رسید تسویه را اینجا رها کنید</b>
                            <span class="co-drop-or">یا کلیک کنید و انتخاب کنید</span>
                            <small>JPG / PNG / WebP — حداکثر ۴ مگابایت</small>
                        </label>
                        <div class="co-file-chip" hidden>
                            <span class="co-chip-ico" aria-hidden="true">📄</span>
                            <span class="co-chip-name" dir="ltr"></span>
                            <span class="co-chip-size"></span>
                            <button type="button" class="co-chip-clear" aria-label="حذف فایل" title="حذف فایل">×</button>
                        </div>
                        <div class="co-file-error" hidden></div>
                    </div>
                    <?php if (isset($errors['settlement_receipt'])): ?><span class="field-error"><?= $h($errors['settlement_receipt']) ?></span><?php endif; ?>
                </div>
                <button type="submit" class="btn btn-orange co-submit">ارسال رسید تسویه ←</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت اطلاعات ارسال انبوه ▂▂ -->
<?php if (in_array($status, ['shipped', 'delivered'], true) && !empty($order['shipping_tracking_code'])): ?>
    <div class="coi-action-box">
        <h2>🚚 اطلاعات ارسال</h2>
        <p><b>شرکت حمل:</b> <?= $h($order['shipping_carrier'] ?? '—') ?>
            &nbsp;|&nbsp;
            <b>کد رهگیری:</b> <span dir="ltr"><?= $h($order['shipping_tracking_code'] ?? '—') ?></span>
        </p>
        <p><b>ارسال در:</b> <?= $h(co_fa_date($order['shipped_at'] ?? null)) ?></p>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت فاکتور نهایی ▂▂ -->
<?php if ($status === 'delivered' && !empty($order['final_invoice_number'])): ?>
    <div class="coi-action-box">
        <h2>📦 تحویل و فاکتور نهایی</h2>
        <p><b>تحویل در:</b> <?= $h(co_fa_date($order['delivered_at'] ?? null)) ?></p>
        <p><b>شماره فاکتور نهایی:</b> <span dir="ltr"><?= $h($order['final_invoice_number']) ?></span></p>
        <p><b>مبلغ فاکتور:</b> <?= $order['final_invoice_amount'] !== null ? number_format((float)$order['final_invoice_amount']) . ' تومان' : '—' ?></p>
        <p><b>تاریخ فاکتور:</b> <?= $h(co_fa_date($order['final_invoice_at'] ?? null)) ?></p>
        <p class="field-hint">سفارش شما با موفقیت تکمیل شد. سپس از اعتماد شما 🙏</p>
        <p style="margin-top:12px;">
            <a class="btn btn-orange" target="_blank" rel="noopener"
                href="/custom-invoice-print.php?id=<?= (int)$order['id'] ?>&type=final&code=<?= urlencode((string)$order['tracking_code']) ?><?= $mobile !== '' ? '&mobile=' . urlencode($mobile) : '' ?>">
                🖨 مشاهده / چاپ فاکتور نهایی
            </a>
        </p>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت امضای توافق‌نامه (اختیاری) ▂▂ -->
<?php if ($status === 'agreement'): ?>
    <div class="coi-action-box">
        <h2>✍️ امضای توافق‌نامه</h2>
        <div class="coi-agreement-text">
            <b>بندهای توافق‌نامه تولید اختصاصی (خلاصه):</b>
            <ol>
                <li>حداقل سفارش ۵۰۰ جفت است؛ قیمت واحد پس از نهایی‌شدن جزئیات توسط کارشناس اعلام و در قرارداد درج می‌شود.</li>
                <li>پیش‌پرداخت درصدی از مبلغ قرارداد برای ورود سفارش به خط تولید الزامی است؛ تولید بدون پیش‌پرداخت آغاز نمی‌شود.</li>
                <li>طرح و لوگوی ارسالی باید حقوق مالکیت معنوی شما را داشته باشد؛ مسئولیت نقض حقوق برند شخص ثالث با سفارش‌دهنده است.</li>
                <li>زمان تولید پس از تایید نمونه/جزئیات، طبق اعلام کارشناس محاسبه می‌شود و تاخیر پرداخت، زمان تحویل را جابه‌جا می‌کند.</li>
                <li>پس از اطلاع‌رسانی آماده‌شدن کالا، تسویه‌ی باقی‌مانده و تحویل طبق توافق انجام می‌شود.</li>
            </ol>
        </div>
        <form method="post" action="" enctype="multipart/form-data" novalidate>
            <?= co_csrf_field() ?>
            <input type="hidden" name="action" value="sign">
            <input type="hidden" name="code" value="<?= $h($order['tracking_code']) ?>">
            <input type="hidden" name="mobile" value="<?= $h($mobile) ?>">
            <div class="form-row">
                <div class="form-field">
                    <label>نام و نام خانوادگی امضاکننده <i>*</i></label>
                    <input type="text" name="signature_name" value="<?= $h($_POST['signature_name'] ?? '') ?>">
                    <?php if (isset($errors['signature_name'])): ?><span class="field-error"><?= $h($errors['signature_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-field">
                    <label>کد ملی <i>*</i></label>
                    <input type="text" name="national_code" dir="ltr" maxlength="10" inputmode="numeric" value="<?= $h($_POST['national_code'] ?? '') ?>">
                    <?php if (isset($errors['national_code'])): ?><span class="field-error"><?= $h($errors['national_code']) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="form-field">
                <label>اسکن/عکس صفحه امضاشده‌ی قرارداد <span class="co-optional">(اختیاری)</span></label>
                <div class="co-drop">
                    <input type="file" name="agreement_scan" id="coFileScan-<?= (int)$order['id'] ?>" class="co-file-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <label class="co-dropzone" for="coFileScan-<?= (int)$order['id'] ?>">
                        <span class="co-drop-ico" aria-hidden="true">✍️</span>
                        <b>عکس قرارداد امضاشده را اینجا رها کنید</b>
                        <span class="co-drop-or">یا کلیک کنید و انتخاب کنید</span>
                        <small>JPG / PNG / WebP — حداکثر ۴ مگابایت</small>
                    </label>
                    <div class="co-file-chip" hidden>
                        <span class="co-chip-ico" aria-hidden="true">📄</span>
                        <span class="co-chip-name" dir="ltr"></span>
                        <span class="co-chip-size"></span>
                        <button type="button" class="co-chip-clear" aria-label="حذف فایل" title="حذف فایل">×</button>
                    </div>
                    <div class="co-file-error" hidden></div>
                </div>
                <?php if (isset($errors['agreement_scan'])): ?><span class="field-error"><?= $h($errors['agreement_scan']) ?></span><?php endif; ?>
            </div>
            <label class="coi-check">
                <input type="checkbox" name="agree" value="1">
                <span>بندهای فوق را می‌خوانم و می‌پذیرم و این ورود را امضای من می‌دانم <i>*</i></span>
            </label>
            <?php if (isset($errors['agree'])): ?><span class="field-error"><?= $h($errors['agree']) ?></span><?php endif; ?>
            <button type="submit" class="btn btn-orange co-submit">امضا و ثبت توافق‌نامه ←</button>
        </form>
    </div>
<?php endif; ?>

<!-- ▂▂ کارت بارگذاری کد ملی (اختیاری) ▂▂ -->
<?php if ($status === 'documents'): ?>
    <div class="coi-action-box">
        <h2>🪪 تصویر کد ملی (پشت و رو)</h2>
        <?php if ($documentsSubmitted): ?>
            <div class="form-alert ok" style="background:#f0fdf4;border-color:#86efac;color:#166534;">
                ✅ مدارک شما ارسال شده و در انتظار بررسی کارشناس است.
            </div>
            <?php if (!empty($order['id_card_front_path'])): ?>
                <p class="field-hint">روی کارت ملی: <a href="<?= $h(private_file_url($order['id_card_front_path'])) ?>" target="_blank">مشاهده ↗</a></p>
            <?php endif; ?>
            <?php if (!empty($order['id_card_back_path'])): ?>
                <p class="field-hint">پشت کارت ملی: <a href="<?= $h(private_file_url($order['id_card_back_path'])) ?>" target="_blank">مشاهده ↗</a></p>
            <?php endif; ?>
        <?php else: ?>
            <form method="post" action="" enctype="multipart/form-data" novalidate>
                <?= co_csrf_field() ?>
                <input type="hidden" name="action" value="docs">
                <input type="hidden" name="code" value="<?= $h($order['tracking_code']) ?>">
                <input type="hidden" name="mobile" value="<?= $h($mobile) ?>">
                <div class="form-row">
                    <div class="form-field">
                        <label>تصویر روی کارت ملی <?= empty($order['id_card_front_path']) ? '<i>*</i>' : '<span class="co-optional">(جایگزینی)</span>' ?></label>
                        <div class="co-drop">
                            <input type="file" name="id_front" id="coFileIdFront-<?= (int)$order['id'] ?>" class="co-file-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <label class="co-dropzone" for="coFileIdFront-<?= (int)$order['id'] ?>">
                                <span class="co-drop-ico" aria-hidden="true">🪪</span>
                                <b>تصویر روی کارت ملی</b>
                                <small>JPG / PNG / WebP — حداکثر ۴ مگابایت</small>
                            </label>
                            <div class="co-file-chip" hidden><span class="co-chip-ico">📄</span><span class="co-chip-name" dir="ltr"></span><span class="co-chip-size"></span><button type="button" class="co-chip-clear" aria-label="حذف">×</button></div>
                            <div class="co-file-error" hidden></div>
                        </div>
                        <?php if (isset($errors['id_front'])): ?><span class="field-error"><?= $h($errors['id_front']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label>تصویر پشت کارت ملی <?= empty($order['id_card_back_path']) ? '<i>*</i>' : '<span class="co-optional">(جایگزینی)</span>' ?></label>
                        <div class="co-drop">
                            <input type="file" name="id_back" id="coFileIdBack-<?= (int)$order['id'] ?>" class="co-file-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <label class="co-dropzone" for="coFileIdBack-<?= (int)$order['id'] ?>">
                                <span class="co-drop-ico" aria-hidden="true">🪪</span>
                                <b>تصویر پشت کارت ملی</b>
                                <small>JPG / PNG / WebP — حداکثر ۴ مگابایت</small>
                            </label>
                            <div class="co-file-chip" hidden><span class="co-chip-ico">📄</span><span class="co-chip-name" dir="ltr"></span><span class="co-chip-size"></span><button type="button" class="co-chip-clear" aria-label="حذف">×</button></div>
                            <div class="co-file-error" hidden></div>
                        </div>
                        <?php if (isset($errors['id_back'])): ?><span class="field-error"><?= $h($errors['id_back']) ?></span><?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-orange co-submit">ارسال مدارک ←</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ▂▂ لغو سفارش — تا قبل از تولید انبوه ▂▂ -->
<?php if (in_array($status, ['submitted', 'admin_review', 'down_payment', 'sample_production', 'sample_sent', 'sample_approval', 'settlement', 'agreement', 'documents'], true)): ?>
    <form method="post" action="" class="coi-cancel-box" onsubmit="return confirm('مطمئنید این سفارش لغو شود؟');">
        <?= co_csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="code" value="<?= $h($order['tracking_code']) ?>">
        <input type="hidden" name="mobile" value="<?= $h($mobile) ?>">
        <button type="submit" class="coi-cancel-btn">لغو این سفارش</button>
    </form>
<?php endif; ?>