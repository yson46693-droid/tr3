/**
 * Tables Enhancement Script
 * تحسين الجداول تلقائياً
 */

(function() {
    'use strict';
    
    /**
     * Add data-label attributes to table cells if missing
     */
    function enhanceTables() {
        const tables = document.querySelectorAll('.table-responsive .table');
        
        tables.forEach(table => {
            const thead = table.querySelector('thead');
            const tbody = table.querySelector('tbody');
            
            if (!thead || !tbody) return;
            
            // Get headers
            const headers = Array.from(thead.querySelectorAll('th'));
            const headerTexts = headers.map(th => th.textContent.trim());
            
            // Get all rows
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                
                cells.forEach((cell, index) => {
                    // Skip if data-label already exists
                    if (cell.hasAttribute('data-label')) return;
                    
                    // Skip empty cells
                    if (!cell.textContent.trim()) return;
                    
                    // Skip cells in last column (usually actions)
                    if (index === cells.length - 1 && cell.querySelector('.btn-group')) {
                        cell.setAttribute('data-label', 'الإجراءات');
                        return;
                    }
                    
                    // Add data-label from header
                    if (headerTexts[index]) {
                        cell.setAttribute('data-label', headerTexts[index]);
                    }
                });
            });
        });
    }
    
    /**
     * Initialize on DOM ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceTables);
    } else {
        enhanceTables();
    }
    
    // Re-run after dynamic content is loaded
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            let shouldEnhance = false;
            
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1 && (
                            node.classList.contains('table') || 
                            node.querySelector('.table')
                        )) {
                            shouldEnhance = true;
                        }
                    });
                }
            });
            
            if (shouldEnhance) {
                setTimeout(enhanceTables, 100);
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
})();

