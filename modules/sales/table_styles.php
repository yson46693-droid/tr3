<?php
/**
 * Shared modern table styles for sales module pages.
 *
 * This file outputs a single <style> block the first time it is included
 * to avoid duplicating CSS when multiple sales pages are combined.
 */

if (!defined('SALES_TABLE_STYLES_RENDERED')) {
    define('SALES_TABLE_STYLES_RENDERED', true);
    ?>
    <style>
        :root {
            --sales-table-header-bg: #1d4ed8;
            --sales-table-header-color: #f8fafc;
            --sales-table-header-divider: rgba(248, 250, 252, 0.25);
            --sales-table-row-bg: #ffffff;
            --sales-table-row-alt-bg: #f8fafc;
            --sales-table-row-hover-bg: #eff6ff;
            --sales-table-border: rgba(148, 163, 184, 0.35);
            --sales-table-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
        }

        .sales-table-responsive {
            border-radius: 18px;
            border: 1px solid var(--sales-table-border);
            background: #ffffff;
            box-shadow: var(--sales-table-shadow);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .sales-table-responsive > .table {
            margin-bottom: 0;
        }

        .sales-table {
            border-collapse: separate !important;
            border-spacing: 0;
            background: transparent;
        }

        .sales-table thead th {
            background: var(--sales-table-header-bg);
            color: var(--sales-table-header-color);
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 0.78rem;
            padding: 0.9rem 1.1rem;
            border-bottom: none;
            border-top: none;
            position: relative;
            border-right: 1px solid var(--sales-table-header-divider);
        }

        .sales-table thead th:first-child {
            padding-inline-start: 1.35rem;
        }

        .sales-table thead th:last-child {
            border-right: none;
        }

        .sales-table tbody tr {
            transition: background-color 0.18s ease, transform 0.18s ease;
            background: var(--sales-table-row-bg);
        }

        .sales-table tbody tr:nth-child(even) {
            background: var(--sales-table-row-alt-bg);
        }

        .sales-table tbody tr:hover {
            background: var(--sales-table-row-hover-bg);
            transform: translateY(-1px);
        }

        .sales-table tbody td {
            padding: 0.85rem 1.1rem;
            border-color: var(--sales-table-border);
            color: #1f2937;
            vertical-align: middle;
        }

        .sales-table tbody td:first-child {
            font-weight: 600;
            padding-inline-start: 1.35rem;
        }

        .sales-table tbody td .badge {
            font-size: 0.72rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
        }

        .sales-table tbody td .btn-sm,
        .sales-table tbody td .btn-group-sm > .btn {
            border-radius: 999px;
            font-weight: 600;
        }

        .sales-table tfoot td {
            padding: 0.9rem 1.1rem;
            border-top: 1px solid var(--sales-table-border);
            background: #f8fafc;
            font-weight: 600;
        }

        .sales-table--compact tbody td,
        .sales-table--compact thead th {
            padding: 0.65rem 0.8rem;
            font-size: 0.85rem;
        }

        .sales-table-details {
            border-radius: 16px;
            border: 1px solid var(--sales-table-border);
            overflow: hidden;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        .sales-table-details tr {
            background: #ffffff;
        }

        .sales-table-details tr:nth-child(even) {
            background: #f8fafc;
        }

        .sales-table-details th,
        .sales-table-details td {
            border: none !important;
            padding: 0.75rem 1rem;
        }

        .sales-table-details th {
            width: 38%;
            background: rgba(148, 163, 184, 0.15);
            color: #475569;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-size: 0.75rem;
        }

        .sales-table-details td {
            font-weight: 600;
            color: #0f172a;
        }

        .sales-table-details tr + tr td,
        .sales-table-details tr + tr th {
            border-top: 1px solid rgba(148, 163, 184, 0.25) !important;
        }

        @media (max-width: 768px) {
            .sales-table-responsive {
                border-radius: 14px;
                box-shadow: 0 10px 20px rgba(15, 23, 42, 0.1);
            }

            .sales-table thead th,
            .sales-table tbody td {
                padding-inline: 0.8rem;
            }

            .sales-table tbody td:first-child {
                min-width: 150px;
            }
        }

        @media (max-width: 576px) {
            .sales-table thead th,
            .sales-table tbody td {
                padding-inline: 0.65rem;
                font-size: 0.8rem;
            }

            .sales-table-details th,
            .sales-table-details td {
                display: block;
                width: 100%;
            }

            .sales-table-details th {
                border-bottom: none !important;
                border-top: 1px solid rgba(148, 163, 184, 0.2) !important;
            }

            .sales-table-details tr:first-child th {
                border-top: none !important;
            }

            .sales-table-details td {
                padding-top: 0;
            }
        }
    </style>
    <?php
}
?>

