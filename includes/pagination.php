<?php
/**
 * نظام Pagination للجداول
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

/**
 * إنشاء Pagination
 */
function createPagination($currentPage, $totalPages, $baseUrl, $params = []) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $queryString = '';
    if (!empty($params)) {
        $queryString = '&' . http_build_query($params);
    }
    
    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    // زر السابق
    $html .= '<li class="page-item ' . ($currentPage <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage - 1) . $queryString . '">';
    $html .= '<i class="bi bi-chevron-right"></i></a></li>';
    
    // أرقام الصفحات
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=1' . $queryString . '">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $active = $i == $currentPage ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '">';
        $html .= '<a class="page-link" href="' . $baseUrl . '?page=' . $i . $queryString . '">' . $i . '</a></li>';
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $totalPages . $queryString . '">' . $totalPages . '</a></li>';
    }
    
    // زر التالي
    $html .= '<li class="page-item ' . ($currentPage >= $totalPages ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage + 1) . $queryString . '">';
    $html .= '<i class="bi bi-chevron-left"></i></a></li>';
    
    $html .= '</ul></nav>';
    
    return $html;
}

/**
 * حساب Pagination
 */
function calculatePagination($totalItems, $perPage = 10, $currentPage = 1) {
    $currentPage = max(1, intval($currentPage));
    $totalPages = ceil($totalItems / $perPage);
    $offset = ($currentPage - 1) * $perPage;
    
    return [
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'per_page' => $perPage,
        'offset' => $offset,
        'total_items' => $totalItems
    ];
}

