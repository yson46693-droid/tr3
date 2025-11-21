<?php
/**
 * Shared modern table styles across dashboard modules.
 *
 * Outputs a single <style> block the first time it is included.
 */

if (!defined('GLOBAL_TABLE_STYLES_RENDERED')) {
    define('GLOBAL_TABLE_STYLES_RENDERED', true);
    ?>
    <style>
        :root {
            --global-table-header-bg: #1d4ed8;
            --global-table-header-color: #f8fafc;
            --global-table-header-divider: rgba(248, 250, 252, 0.25);
            --global-table-row-bg: #ffffff;
            --global-table-row-alt-bg: #f8fafc;
            --global-table-row-hover-bg: #eff6ff;
            --global-table-border: rgba(148, 163, 184, 0.35);
            --global-table-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
        }

        .dashboard-table-wrapper {
            border-radius: 18px;
            border: 1px solid var(--global-table-border);
            background: #ffffff;
            box-shadow: var(--global-table-shadow);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .dashboard-table-wrapper > .table {
            margin-bottom: 0;
        }

        .dashboard-table {
            border-collapse: separate !important;
            border-spacing: 0;
            background: transparent;
        }

        .dashboard-table thead th {
            background: var(--global-table-header-bg);
            color: var(--global-table-header-color);
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 0.78rem;
            padding: 0.9rem 1.1rem;
            border-bottom: none;
            border-top: none;
            position: relative;
            border-right: 1px solid var(--global-table-header-divider);
        }

        .dashboard-table thead th:first-child {
            padding-inline-start: 1.35rem;
        }

        .dashboard-table thead th:last-child {
            border-right: none;
        }

        .dashboard-table tbody tr {
            transition: none;
            background: var(--global-table-row-bg);
        }

        .dashboard-table tbody tr:nth-child(even) {
            background: var(--global-table-row-alt-bg);
        }

        .dashboard-table tbody tr:hover {
            background: inherit;
            transform: none;
        }

        .dashboard-table--no-hover tbody tr {
            transition: none !important;
        }

        .dashboard-table--no-hover tbody tr:hover {
            background: inherit !important;
            transform: none !important;
        }

        .dashboard-table tbody td {
            padding: 0.85rem 1.1rem;
            border-color: var(--global-table-border);
            color: #1f2937;
            vertical-align: middle;
        }

        .dashboard-table tbody td:first-child {
            font-weight: 600;
            padding-inline-start: 1.35rem;
        }

        .dashboard-table tbody td .badge {
            font-size: 0.72rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
        }

        .dashboard-table tbody td .btn-sm,
        .dashboard-table tbody td .btn-group-sm > .btn {
            border-radius: 999px;
            font-weight: 600;
        }

        .dashboard-table tfoot td {
            padding: 0.9rem 1.1rem;
            border-top: 1px solid var(--global-table-border);
            background: #f8fafc;
            font-weight: 600;
        }

        .dashboard-table--compact tbody td,
        .dashboard-table--compact thead th {
            padding: 0.65rem 0.8rem;
            font-size: 0.85rem;
        }

        .dashboard-table-details {
            border-radius: 16px;
            border: 1px solid var(--global-table-border);
            overflow: hidden;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        .dashboard-table-details tr {
            background: #ffffff;
        }

        .dashboard-table-details tr:nth-child(even) {
            background: #f8fafc;
        }

        .dashboard-table-details th,
        .dashboard-table-details td {
            border: none !important;
            padding: 0.75rem 1rem;
        }

        .dashboard-table-details th {
            width: 38%;
            background: rgba(148, 163, 184, 0.15);
            color: #475569;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-size: 0.75rem;
        }

        .dashboard-table-details td {
            font-weight: 600;
            color: #0f172a;
        }

        .dashboard-table-details tr + tr td,
        .dashboard-table-details tr + tr th {
            border-top: 1px solid rgba(148, 163, 184, 0.25) !important;
        }

        @media (max-width: 768px) {
            .dashboard-table-wrapper {
                border-radius: 14px;
                box-shadow: 0 10px 20px rgba(15, 23, 42, 0.1);
                overflow-x: auto; /* السماح بالتمرير الأفقي */
                -webkit-overflow-scrolling: touch;
            }
            
            /* تحسين شريط التمرير */
            .dashboard-table-wrapper::-webkit-scrollbar {
                height: 8px;
            }
            
            .dashboard-table-wrapper::-webkit-scrollbar-track {
                background: #f8f9fa;
                border-radius: 4px;
            }
            
            .dashboard-table-wrapper::-webkit-scrollbar-thumb {
                background: #ced4da;
                border-radius: 4px;
            }
            
            .dashboard-table-wrapper::-webkit-scrollbar-thumb:hover {
                background: #adb5bd;
            }

            .dashboard-table {
                min-width: 600px; /* الحد الأدنى لعرض الجدول */
                width: auto;
            }

            .dashboard-table thead th,
            .dashboard-table tbody td {
                padding-inline: 0.8rem;
                white-space: nowrap; /* منع التفاف النص */
            }

            .dashboard-table tbody td:first-child {
                min-width: 150px;
            }
            
            /* السماح بالتفاف النص في خلايا محددة */
            .dashboard-table tbody td.text-wrap,
            .dashboard-table tbody td[data-wrap="true"] {
                white-space: normal;
                word-wrap: break-word;
                max-width: 200px;
            }
        }

        @media (max-width: 576px) {
            .dashboard-table-wrapper {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }
            
            .dashboard-table {
                min-width: 600px; /* الحد الأدنى لعرض الجدول */
                width: auto;
            }
            
            .dashboard-table thead th,
            .dashboard-table tbody td {
                padding-inline: 0.65rem;
                font-size: 0.8rem;
                white-space: nowrap; /* منع التفاف النص */
            }
            
            /* السماح بالتفاف النص في خلايا محددة */
            .dashboard-table tbody td.text-wrap,
            .dashboard-table tbody td[data-wrap="true"] {
                white-space: normal;
                word-wrap: break-word;
                max-width: 200px;
            }

            .dashboard-table-details th,
            .dashboard-table-details td {
                display: block;
                width: 100%;
            }

            .dashboard-table-details th {
                border-bottom: none !important;
                border-top: 1px solid rgba(148, 163, 184, 0.2) !important;
            }

            .dashboard-table-details tr:first-child th {
                border-top: none !important;
            }

            .dashboard-table-details td {
                padding-top: 0;
            }
        }
    </style>
    <?php
}
?>

