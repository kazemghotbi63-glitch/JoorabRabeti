<?php

declare(strict_types=1);

/* [FILE] app/services/PdfStyle.php — استایل فاکتور PDF */

final class PdfStyle
{
    public static function get(): string
    {
        return '
* {
    font-family: dejavusans;
}
body {
    direction: rtl;
    font-family: dejavusans;
    font-size: 8pt;
    color: #222222;
    line-height: 1.55;
}
table { border-collapse: collapse; }

/* ─── Header ─── */
.header {
    width: 100%;
    border-bottom: 0.7mm solid #222222;
    padding-bottom: 3mm;
    margin-bottom: 4mm;
}
.header-table { width: 100%; }
.logo-cell { width: 25%; vertical-align: middle; text-align: right; }
.logo-cell img { max-width: 22mm; max-height: 22mm; }
.title-cell { width: 75%; vertical-align: middle; text-align: right; }
.brand { font-size: 16pt; font-weight: bold; line-height: 1.3; }
.subtitle { color: #777777; font-size: 8pt; line-height: 1.5; }
.invoice-title { font-size: 12pt; font-weight: bold; margin-top: 1mm; }

/* ─── Meta ─── */
.meta { width: 100%; margin-bottom: 4mm; }
.meta td {
    border: 0.25mm solid #dddddd;
    padding: 2.2mm;
    vertical-align: middle;
    line-height: 1.45;
}
.meta-label { width: 14%; background-color: #f5f5f5; font-weight: bold; }
.meta-value { width: 19.3%; }
.ltr { direction: ltr; unicode-bidi: embed; }

/* ─── Parties ─── */
.parties { width: 100%; margin-bottom: 4mm; }
.party { width: 49%; vertical-align: top; }
.party-gap { width: 2%; }
.party-box { border: 0.25mm solid #dddddd; padding: 3mm; min-height: 29mm; }
.party-title {
    font-size: 9pt; font-weight: bold;
    border-bottom: 0.5mm solid #222222;
    padding-bottom: 1.5mm; margin-bottom: 2mm;
}
.party-row { line-height: 1.65; margin-bottom: 0.8mm; }
.party-label { font-weight: bold; }

/* ─── Sections / Items ─── */
.section-title {
    font-size: 9pt; font-weight: bold;
    border-bottom: 0.5mm solid #222222;
    padding-bottom: 1.5mm; margin-bottom: 2mm;
}
.items { width: 100%; }
.items th {
    background-color: #222222; color: #ffffff;
    border: 0.25mm solid #222222;
    padding: 2.2mm 1.5mm; vertical-align: middle;
    font-size: 7.5pt; line-height: 1.4;
}
.items td {
    border: 0.25mm solid #d5d5d5;
    padding: 2.5mm 1.5mm; vertical-align: middle; line-height: 1.55;
}
.center { text-align: center; }
.product { text-align: right; }
.product-name { font-size: 8pt; line-height: 1.55; }
.product-code { font-size: 6.8pt; color: #777777; margin-top: 0.7mm; }
.money { text-align: center; direction: rtl; }
.empty { text-align: center; padding: 5mm; color: #777777; }

/* ─── Bottom ─── */
.bottom { width: 100%; margin-top: 4mm; }
.bottom-left { width: 55%; vertical-align: top; padding-left: 3mm; }
.bottom-right { width: 45%; vertical-align: top; }
.bottom-box { border: 0.25mm solid #dddddd; padding: 2.8mm; }
.box-title {
    font-weight: bold; font-size: 8.5pt;
    border-bottom: 0.4mm solid #222222;
    padding-bottom: 1.3mm; margin-bottom: 2mm;
}
.note { line-height: 1.8; }
.muted { color: #777777; }

/* ─── Totals ─── */
.totals { width: 100%; }
.totals td {
    border-bottom: 0.25mm solid #dddddd;
    padding: 2mm; line-height: 1.45;
}
.total-label { width: 48%; background-color: #f7f7f7; font-weight: bold; }
.total-value { width: 52%; text-align: left; font-weight: bold; }
.grand-total td {
    border-top: 0.6mm solid #222222;
    border-bottom: 0.6mm solid #222222;
    font-size: 9pt; padding-top: 2.5mm; padding-bottom: 2.5mm;
}
.remaining td { background-color: #f1f1f1; }
.pending-row td { color: #b45309; }

/* ─── Refunds ─── */
.refund-row td { background-color: #eef6ee; color: #1a7f4b; font-weight: bold; }

/* ─── Footer ─── */
.footer {
    width: 100%; border-top: 0.25mm solid #dddddd;
    margin-top: 4mm; padding-top: 2mm;
    text-align: center; color: #777777; font-size: 6.5pt;
}

/* ─── مالیات و تخفیف ردیف‌ها اگر بود ─── */
.discount-row td { color: #b45309; }
';
    }
}
