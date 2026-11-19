<?php
declare(strict_types=1);

/*
 * Dompdf CSS shared by send_invoice.php and send_proforma.php. It maps the same
 * .uh-* class names used by invoice_view.php; keep the selectors in sync with style.css.
 */
return <<<'CSS'
@page { margin: 24px; }

body {
    background: #ffffff;
    color: #1e293b;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    margin: 0;
    padding: 0;
}

.uh-pdf-wrap {
    position: relative;
    background: #ffffff;
    padding: 0;
}

.uh-pdf-watermark {
    position: fixed;
    top: 22%;
    left: 14%;
    width: 460px;
    opacity: .045;
    z-index: 0;
}

.uh-pdf-content {
    position: relative;
    z-index: 1;
}

.uh-paper {
    width: 100%;
    max-width: none;
    margin: 0;
    background: #ffffff;
    box-shadow: none;
    overflow: hidden;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #1e293b;
}

.uh-header {
    background: #1a2a72;
    padding: 20px 30px;
}

.uh-header-table,
.uh-brand-table,
.uh-meta-table,
.uh-parties-table,
.uh-table,
.uh-totals-table,
.uh-payment-table,
.uh-footer-table {
    width: 100%;
    border-collapse: collapse;
}

.uh-brand-cell {
    width: 70%;
    vertical-align: middle;
}

.uh-doc-cell {
    width: 30%;
    text-align: right;
    vertical-align: middle;
}

.uh-logo-cell {
    width: 62px;
    vertical-align: middle;
}

.uh-logo-img {
    width: auto;
    max-width: 300px;
    height: 64px;
    object-fit: contain;
}

.uh-logo-fallback {
    width: 56px;
    height: 56px;
    background: #fc7f10;
    color: #ffffff;
    text-align: center;
    font-weight: bold;
    font-size: 24px;
    line-height: 56px;
    border-radius: 8px;
}

.uh-wordmark {
    padding-left: 12px;
    vertical-align: middle;
}

.uh-wordmark-main {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 21px;
    font-weight: 700;
    line-height: 1;
    color: #ffffff;
}

.uh-wordmark-main .or {
    color: #fc7f10;
}

.uh-wordmark-main .wh {
    color: #ffffff;
}

.uh-wordmark-sub {
    color: rgba(255,255,255,.68);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    padding-top: 3px;
}
.uh-company-tagline { color:rgba(255,255,255,.78);font-size:10px;font-style:italic;padding-top:4px; }
.uh-brand-detail { color:#64748b;font-size:10px;line-height:1.6; }

.uh-doc-badge,
.uh-doc-badge-invoice {
    display: inline-block;
    background: #fc7f10;
    border-radius: 5px;
    color: #ffffff;
    font-size: 16px;
    font-weight: 900;
    letter-spacing: 2px;
    padding: 8px 18px;
    text-transform: uppercase;
}

.uh-meta-bar {
    background: #e8ebf7;
    padding: 10px 30px;
}

.uh-meta-table td {
    width: 25%;
    padding-right: 10px;
    vertical-align: top;
}

.uh-meta-item label,
.uh-pdf-label {
    display: block;
    color: #64748b;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    margin-bottom: 3px;
}

.uh-meta-item .val,
.uh-pdf-value {
    color: #1a2a72;
    font-size: 11px;
    font-weight: 700;
}

.uh-status-badge {
    display: inline-block;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 10px;
    border: 1px solid #fc7f10;
    color: #b45309;
    background: #fff7e6;
}

.uh-status-paid {
    background: #e8f5e9;
    border-color: #2e7d32;
    color: #2e7d32;
}

.uh-status-unpaid {
    background: #fff7e6;
    border-color: #fc7f10;
    color: #b45309;
}

.uh-status-partial {
    background: #fff7e6;
    border-color: #b45309;
    color: #b45309;
}

.uh-status-void {
    background: #f1f5f9;
    border-color: #64748b;
    color: #64748b;
}

.uh-parties {
    padding: 20px 30px;
    border-bottom: 1px solid #e2e8f0;
}

.uh-parties-table td {
    width: 50%;
    vertical-align: top;
}

.uh-party-left {
    padding-right: 22px;
    border-right: 1px solid #e2e8f0;
}

.uh-party-right {
    padding-left: 22px;
}

.uh-party-label,
.uh-section-label {
    color: #fc7f10;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1.8px;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.uh-party-name {
    color: #1a2a72;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 3px;
}

.uh-party-detail {
    color: #64748b;
    font-size: 10.5px;
    line-height: 1.6;
}

.uh-table-wrap {
    padding: 0 30px;
}

.uh-table {
    margin: 0;
}

.uh-table th {
    background: #1a2a72;
    color: #ffffff;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .8px;
    padding: 9px 10px;
    text-align: left;
    text-transform: uppercase;
}

.uh-table th.r,
.uh-table td.r {
    text-align: right;
}

.uh-table td {
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-size: 10.5px;
    padding: 10px;
    vertical-align: middle;
}

.uh-table tr:nth-child(even) td {
    background: #f8fafc;
}

.uh-empty-row {
    text-align: center;
    color: #94a3b8;
    padding: 22px;
}

.uh-totals-section {
    padding: 18px 30px;
    border-top: 1.5px solid #1a2a72;
}

.uh-totals-table {
    width: 320px;
    margin-left: auto;
}

.uh-totals-table td {
    border-bottom: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 11px;
    padding: 6px 0;
}

.uh-totals-table .value {
    text-align: right;
    font-weight: 700;
}

.uh-totals-table .grand td {
    border-top: 1.5px solid #1a2a72;
    border-bottom: none;
    color: #1a2a72;
    font-size: 13px;
    font-weight: 900;
    padding-top: 10px;
}

.uh-totals-table .grand .value {
    color: #fc7f10;
}

.uh-payment {
    padding: 0 30px 18px;
}

.uh-payment-box {
    border-top: 1px solid #e2e8f0;
    padding-top: 14px;
}

.uh-payment-detail {
    color: #64748b;
    font-size: 10.5px;
    line-height: 1.7;
}

.uh-notes {
    margin: 0 30px 18px;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    padding: 12px 14px;
    background: #fafbfd;
}

.uh-notes p {
    color: #475569;
    font-size: 10.5px;
    line-height: 1.6;
    margin: 0;
}

.uh-signature {
    padding: 20px 30px 28px;
    text-align: right;
}

.uh-sig-box {
    display: inline-block;
    width: 260px;
    text-align: center;
}

.uh-sig-space {
    height: 46px;
}

.uh-sig-line {
    border-top: 1px solid #1a2a72;
    color: #475569;
    font-size: 10.5px;
    padding-top: 7px;
}

.uh-sig-for {
    color: #1a2a72;
    font-size: 10.5px;
    font-weight: 700;
    margin-top: 2px;
}

.uh-footer {
    background: #1a2a72;
    border-top: 3px solid #fc7f10;
    padding: 12px 30px;
}

.uh-footer-table td {
    font-size: 9.5px;
    vertical-align: middle;
}

.uh-footer-left {
    color: rgba(255,255,255,.64);
}

.uh-footer-right {
    color: #fc7f10;
    text-align: right;
}
CSS;
