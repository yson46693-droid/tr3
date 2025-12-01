/**
 * Image Lazy Loading Enhancement
 * تحسين تحميل الصور بشكل كسول
 */

(function() {
    'use strict';

    // Check if Intersection Observer is supported
    const supportsIntersectionObserver = 'IntersectionObserver' in window;

    /**
     * Lazy load images using Intersection Observer
     */
    function lazyLoadImages() {
        // Select all images with loading="lazy" attribute
        const lazyImages = document.querySelectorAll('img[loading="lazy"]');
        
        if (!lazyImages.length) {
            return;
        }

        if (supportsIntersectionObserver) {
            // Use Intersection Observer (modern browsers)
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        
                        // Load the image
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            delete img.dataset.src;
                        }
                        
                        // Load srcset if available
                        if (img.dataset.srcset) {
                            img.srcset = img.dataset.srcset;
                            delete img.dataset.srcset;
                        }
                        
                        // Load sizes if available
                        if (img.dataset.sizes) {
                            img.sizes = img.dataset.sizes;
                            delete img.dataset.sizes;
                        }
                        
                        // Add loaded class
                        img.classList.add('loaded');
                        
                        // Handle load event
                        img.addEventListener('load', function() {
                            img.classList.remove('lazy-blur');
                        }, { once: true });
                        
                        // Handle error event
                        img.addEventListener('error', function() {
                            img.classList.add('error');
                            console.warn('Failed to load image:', img.src || img.dataset.src);
                        }, { once: true });
                        
                        // Stop observing this image
                        observer.unobserve(img);
                    }
                });
            }, {
                rootMargin: '50px', // Start loading 50px before image enters viewport
                threshold: 0.01
            });

            // Observe each lazy image
            lazyImages.forEach(img => {
                imageObserver.observe(img);
            });
        } else {
            // Fallback for older browsers
            lazyImages.forEach(img => {
                // Load immediately on older browsers
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    delete img.dataset.src;
                }
                if (img.dataset.srcset) {
                    img.srcset = img.dataset.srcset;
                    delete img.dataset.srcset;
                }
                if (img.dataset.sizes) {
                    img.sizes = img.dataset.sizes;
                    delete img.dataset.sizes;
                }
                img.classList.add('loaded');
            });
        }
    }

    /**
     * Add loading="lazy" to images that don't have it
     */
    function addLazyLoadingToImages() {
        const images = document.querySelectorAll('img:not([loading])');
        
        images.forEach(img => {
            // Skip images that are already in viewport
            const rect = img.getBoundingClientRect();
            const isInViewport = rect.top < window.innerHeight && rect.bottom > 0;
            
            if (!isInViewport) {
                // Store original src in data-src
                if (img.src && !img.dataset.src) {
                    img.dataset.src = img.src;
                    img.removeAttribute('src');
                }
                
                // Store srcset if exists
                if (img.srcset && !img.dataset.srcset) {
                    img.dataset.srcset = img.srcset;
                    img.removeAttribute('srcset');
                }
                
                // Add loading attribute
                img.setAttribute('loading', 'lazy');
            }
        });
    }

    /**
     * Handle picture elements
     */
    function lazyLoadPictures() {
        const pictures = document.querySelectorAll('picture');
        
        if (!pictures.length) {
            return;
        }

        if (supportsIntersectionObserver) {
            const pictureObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const picture = entry.target;
                        const img = picture.querySelector('img');
                        const sources = picture.querySelectorAll('source');
                        
                        // Load sources
                        sources.forEach(source => {
                            if (source.dataset.srcset) {
                                source.srcset = source.dataset.srcset;
                                delete source.dataset.srcset;
                            }
                        });
                        
                        // Load image
                        if (img && img.dataset.src) {
                            img.src = img.dataset.src;
                            delete img.dataset.src;
                        }
                        
                        if (img) {
                            img.classList.add('loaded');
                        }
                        
                        observer.unobserve(picture);
                    }
                });
            }, {
                rootMargin: '50px',
                threshold: 0.01
            });

            pictures.forEach(picture => {
                pictureObserver.observe(picture);
            });
        }
    }

    /**
     * Optimize image loading based on connection speed
     */
    function optimizeForConnection() {
        if ('connection' in navigator) {
            const connection = navigator.connection;
            const effectiveType = connection.effectiveType;
            
            // Adjust rootMargin based on connection speed
            if (effectiveType === 'slow-2g' || effectiveType === '2g') {
                // Load images closer to viewport on slow connections
                return '10px';
            } else if (effectiveType === '3g') {
                return '30px';
            } else {
                return '50px';
            }
        }
        return '50px'; // Default
    }

    /**
     * Initialize lazy loading
     */
    function init() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                lazyLoadImages();
                lazyLoadPictures();
            });
        } else {
            lazyLoadImages();
            lazyLoadPictures();
        }
        
        // Also add lazy loading to dynamically added images
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        if (node.tagName === 'IMG' && node.hasAttribute('loading') && node.getAttribute('loading') === 'lazy') {
                            // Re-run lazy loading
                            setTimeout(lazyLoadImages, 100);
                        }
                    }
                });
            });
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Initialize when script loads
    init();
})();

