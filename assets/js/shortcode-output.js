document.addEventListener('DOMContentLoaded', function() {
    const shortcodeContainers = document.querySelectorAll('[id^="wp-ai-workflows-output-"]');
    
    // Debug logging controller
    const debugLogger = {
        enabled: false, // Set to true to enable console logging
        log: function(...args) {
            if (this.enabled) console.log(...args);
        },
        error: function(...args) {
            if (this.enabled) console.error(...args);
        },
        enable: function() {
            this.enabled = true;
            console.log('Debug logging enabled for WP AI Workflows');
        },
        disable: function() {
            //console.log('Debug logging disabled for WP AI Workflows');
            this.enabled = false;
        }
    };
    
    // Make debug logger globally accessible
    window.wpAiWorkflowsDebug = debugLogger;
    
    // Output Cache Class
    class OutputCache {
        constructor(workflowId, duration) {
            this.workflowId = workflowId;
            this.duration = duration * 1000;
            this.cacheKey = `wp_ai_output_${workflowId}`;
        }
        
        get() {
            try {
                const cached = localStorage.getItem(this.cacheKey);
                if (!cached) return null;
                
                const { data, timestamp } = JSON.parse(cached);
                if (Date.now() - timestamp > this.duration) {
                    localStorage.removeItem(this.cacheKey);
                    return null;
                }
                return data;
            } catch (e) {
                debugLogger.error('Cache read error:', e);
                return null;
            }
        }
        
        set(data) {
            try {
                localStorage.setItem(this.cacheKey, JSON.stringify({
                    data,
                    timestamp: Date.now()
                }));
            } catch (e) {
                debugLogger.error('Cache write error:', e);
            }
        }
        
        clear() {
            localStorage.removeItem(this.cacheKey);
        }
    }
    
    shortcodeContainers.forEach(container => {
        const workflowId = container.dataset.workflowId;
        const sessionId = wpAiWorkflowsShortcode.sessionId;
        const config = wpAiWorkflowsShortcode.config;
        
        // Initialize cache
        const cache = config.cache ? new OutputCache(workflowId, config.cacheDuration) : null;
        
        // Track state
        let lastKnownTimestamp = 0;
        let lastContent = null;
        let lastDisplayedTimestamp = 0; // Track what we actually showed to user
        let pollInterval = null;
        let eventSource = null;
        let isInitialLoad = true; // Track if this is the first fetch
        let ignoredStaleTimestamp = 0; // Track stale data we chose not to show
        
        // Timing constants
        const RECENT_THRESHOLD = 10000; // 10 seconds - data is "fresh" if created within this time
        const AGGRESSIVE_INTERVAL = 1000; // 1 second when waiting for new data
        const NORMAL_INTERVAL = config.refreshInterval || 5000; // Default 5 seconds
        
        function showLoadingState(forceSpinner = false) {
            const loadingType = forceSpinner ? 'spinner' : config.loading;
            
            switch(loadingType) {
                case 'spinner':
                    container.innerHTML = `
                        <div class="wp-ai-workflows-loading">
                            <div class="loading-spinner"></div>
                            <div class="loading-text">${config.loadingText}</div>
                        </div>
                    `;
                    break;
                
                case 'skeleton':
                    container.innerHTML = `
                        <div class="wp-ai-workflows-skeleton">
                            <div class="skeleton-line"></div>
                            <div class="skeleton-line short"></div>
                            <div class="skeleton-line"></div>
                            <div class="skeleton-line short"></div>
                            <div class="skeleton-line"></div>
                        </div>
                    `;
                    break;
                
                case 'previous':
                    if (lastContent) {
                        container.classList.add('loading-overlay');
                        const indicator = container.querySelector('.loading-indicator');
                        if (!indicator) {
                            const loadingIndicator = document.createElement('div');
                            loadingIndicator.className = 'loading-indicator';
                            loadingIndicator.innerHTML = '<span></span><span></span><span></span>';
                            container.appendChild(loadingIndicator);
                        }
                    } else {
                        showLoadingState(true);
                    }
                    break;
                
                case 'custom':
                    container.innerHTML = `<div class="wp-ai-workflows-loading-custom"></div>`;
                    break;
            }
        }

        function formatOutput(content, format = null) {
            const outputFormat = format || config.format;
            
            try {
                switch(outputFormat) {
                    case 'json':
                        if (typeof content === 'string') {
                            try {
                                content = JSON.parse(content);
                            } catch (e) {
                                // Not valid JSON, treat as text
                            }
                        }
                        return `<pre class="json-output">${JSON.stringify(content, null, 2)}</pre>`;
                    
                    case 'markdown':
                        return `<div class="markdown-output">${content}</div>`;
                    
                    case 'text':
                        return `<pre class="text-output">${content}</pre>`;
                    
                    case 'html':
                        return content;
                    
                    case 'auto':
                    default:
                        if (typeof content === 'object') {
                            return `<pre class="json-output">${JSON.stringify(content, null, 2)}</pre>`;
                        } else if (content.trim().startsWith('<') && content.trim().endsWith('>')) {
                            return content;
                        } else {
                            return `<div class="auto-output">${content}</div>`;
                        }
                }
            } catch (e) {
                debugLogger.error('Format error:', e);
                return `<div class="format-error">${content}</div>`;
            }
        }

        function showOutput(content, timestamp = null) {
            // Remove loading states
            container.classList.remove('loading-overlay');
            const indicator = container.querySelector('.loading-indicator');
            if (indicator) indicator.remove();
            
            // Format the content
            const formattedContent = formatOutput(content);
            
            // Build output with optional timestamp
            let outputHtml = `<div class="wp-ai-workflows-output-content">`;
            
            if (config.showTimestamp && timestamp) {
                const timestampStr = new Date(timestamp).toLocaleString();
                outputHtml += `<div class="output-timestamp">Updated: ${timestampStr}</div>`;
            }
            
            outputHtml += formattedContent;
            outputHtml += `</div>`;
            
            container.innerHTML = outputHtml;
            
            // Update cache
            if (cache) {
                cache.set({
                    content: content,
                    timestamp: timestamp || Date.now()
                });
            }
        }

        function showError(message) {
            if (config.errorDisplay === 'hidden') {
                debugLogger.error('Workflow error:', message);
                return;
            }
            
            container.classList.remove('loading-overlay');
            const indicator = container.querySelector('.loading-indicator');
            if (indicator) indicator.remove();
            
            if (config.errorDisplay === 'modal') {
                alert(`Error: ${message}`);
            } else {
                container.innerHTML = `
                    <div class="wp-ai-workflows-error">
                        <span class="error-icon">⚠️</span>
                        ${message}
                    </div>
                `;
            }
        }

        function processData(data) {
            if (!data.output) return { processed: false, isWaiting: false };
            
            const currentTimestamp = data.created_at_timestamp ? 
                data.created_at_timestamp * 1000 : 
                new Date(data.created_at.replace(' ', 'T')).getTime();
            
            const updatedTimestamp = data.updated_at_timestamp ? 
                data.updated_at_timestamp * 1000 : 
                currentTimestamp;
            
            const now = Date.now();
            const dataAge = now - currentTimestamp;
            const processingTime = updatedTimestamp - currentTimestamp;
            const isStillProcessing = processingTime < 15000; // Less than 15 seconds between created and updated
            const isRecent = dataAge < RECENT_THRESHOLD;
            const isNew = currentTimestamp > lastKnownTimestamp;
            const contentChanged = data.output !== lastContent;
            
            // On initial load (like after page refresh)
            if (isInitialLoad) {
                const timeSinceUpdate = now - updatedTimestamp;
                const isStale = config.clearOnRefresh && timeSinceUpdate > config.staleAfter;
                
                debugLogger.log(`[Initial load] Data from ${Math.round(dataAge / 1000)}s ago, last updated ${Math.round(timeSinceUpdate / 1000)}s ago`);
                
                // If clearOnRefresh is enabled and data is stale, don't show it
                if (isStale) {
                    debugLogger.log(`Data is stale (>${config.staleAfter / 1000}s old), not displaying`);
                    isInitialLoad = false;
                    lastKnownTimestamp = currentTimestamp; // Track it so we know when new data arrives
                    lastContent = data.output;
                    ignoredStaleTimestamp = currentTimestamp; // Remember we ignored this
                    
                    // Show empty state
                    container.innerHTML = `
                        <div class="wp-ai-workflows-empty">
                            <span>Ready for new input.</span>
                        </div>
                    `;
                    return { processed: false, isWaiting: false };
                }
                
                // If data is recent and still processing, show loading
                if (isRecent && isStillProcessing) {
                    debugLogger.log('⏳ Workflow still processing (updated_at - created_at < 15s)');
                    isInitialLoad = false;
                    showLoadingState();
                    return { processed: false, isWaiting: true };
                }
                
                // Otherwise display what we have (if not stale)
                lastKnownTimestamp = currentTimestamp;
                lastContent = data.output;
                isInitialLoad = false;
                
                try {
                    const parsed = JSON.parse(data.output);
                    const outputNode = Object.values(parsed).find(n => n.type === 'output');
                    const aiNode = Object.values(parsed).find(n => n.type === 'aiModel');
                    const content = outputNode?.content || aiNode?.content;
                    
                    if (content) {
                        const displayContent = typeof content === 'object' ? 
                            JSON.stringify(content, null, 2) : content;
                        
                        showOutput(displayContent, currentTimestamp);
                        lastDisplayedTimestamp = currentTimestamp;
                        return { processed: true, isWaiting: false };
                    }
                } catch (e) {
                    debugLogger.error('Error processing output:', e);
                }
                return { processed: false, isWaiting: false };
            }
            
            debugLogger.log(`[Workflow ${workflowId}]`, {
                dataAge: Math.round(dataAge / 1000) + 's ago',
                processingTime: Math.round(processingTime / 1000) + 's',
                isStillProcessing,
                isRecent,
                isNew,
                contentChanged
            });
            
            // If data is recent and still processing, keep waiting
            if (isRecent && isStillProcessing && currentTimestamp !== lastDisplayedTimestamp && currentTimestamp !== ignoredStaleTimestamp) {
                debugLogger.log('⏳ Workflow still processing, waiting for completion...');
                if (!container.querySelector('.wp-ai-workflows-loading, .wp-ai-workflows-skeleton, .loading-overlay')) {
                    showLoadingState();
                }
                return { processed: false, isWaiting: true };
            }
            
            // If data is recent but not new, someone likely just submitted
            // BUT only if this isn't the data we just displayed or ignored as stale
            if (isRecent && !isNew && currentTimestamp !== lastDisplayedTimestamp && currentTimestamp !== ignoredStaleTimestamp) {
                debugLogger.log('⏳ Recent submission detected, waiting for results...');
                if (!container.querySelector('.wp-ai-workflows-loading, .wp-ai-workflows-skeleton, .loading-overlay')) {
                    showLoadingState();
                }
                return { processed: false, isWaiting: true };
            }
            
            // We have new data!
            if (isNew || contentChanged) {
                debugLogger.log('✅ New data detected!');
                lastKnownTimestamp = currentTimestamp;
                lastContent = data.output;
                
                try {
                    const parsed = JSON.parse(data.output);
                    
                    // Find content to display
                    const outputNode = Object.values(parsed).find(n => n.type === 'output');
                    const aiNode = Object.values(parsed).find(n => n.type === 'aiModel');
                    const content = outputNode?.content || aiNode?.content;
                    
                    if (content) {
                        // Handle content that might be an object
                        const displayContent = typeof content === 'object' ? 
                            JSON.stringify(content, null, 2) : content;
                        
                        showOutput(displayContent, currentTimestamp);
                        lastDisplayedTimestamp = currentTimestamp; // Track what we displayed
                        ignoredStaleTimestamp = 0; // Clear ignored stale data
                        
                        // Stop polling if configured to do so
                        if (config.stopOnSuccess) {
                            debugLogger.log('New data received, stopping polling (stopOnSuccess enabled)');
                            stopPolling();
                            if (eventSource) {
                                eventSource.close();
                                eventSource = null;
                            }
                        }
                        
                        return { processed: true, isWaiting: false };
                    }
                } catch (e) {
                    debugLogger.error('Error processing output:', e);
                    showError('Error processing output data.');
                }
            }
            
            // Only return isWaiting: true if we're actually waiting for something
            // and it's not the stale data we chose to ignore
            return { 
                processed: false, 
                isWaiting: (isRecent || isStillProcessing) && 
                          currentTimestamp !== lastDisplayedTimestamp && 
                          currentTimestamp !== ignoredStaleTimestamp 
            };
        }

        function fetchOutput() {
            // Skip cache if we're showing loading state
            const isLoading = container.querySelector('.wp-ai-workflows-loading, .wp-ai-workflows-skeleton, .loading-overlay');
            
            // Check cache first (unless we're actively waiting)
            if (cache && !isLoading) {
                const cachedData = cache.get();
                if (cachedData) {
                    showOutput(cachedData.content, cachedData.timestamp);
                    return;
                }
            }
        
            fetch(`${wpAiWorkflowsShortcode.apiRoot}wp-ai-workflows/v1/shortcode-output?workflow_id=${workflowId}&session_id=${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.output) {
                        const { processed, isWaiting } = processData(data);
                        
                        // Adjust polling interval based on state
                        if (isWaiting) {
                            if (!pollInterval || pollInterval._interval !== AGGRESSIVE_INTERVAL) {
                                debugLogger.log('Switching to aggressive polling...');
                                stopPolling();
                                startPolling(AGGRESSIVE_INTERVAL);
                            }
                        } else {
                            if (pollInterval && pollInterval._interval === AGGRESSIVE_INTERVAL) {
                                debugLogger.log('Switching back to normal polling...');
                                stopPolling();
                                startPolling(NORMAL_INTERVAL);
                            }
                        }
                    } else if (data.status === 'no_data' && !lastContent) {
                        container.innerHTML = `
                            <div class="wp-ai-workflows-empty">
                                <span>No output available yet.</span>
                            </div>
                        `;
                    }
                })
                .catch((error) => {
                    debugLogger.error("Fetch error:", error);
                    if (!lastContent) {
                        showError('Error fetching output.');
                    }
                });
        }

        function initializeEventSource() {
            if (!config.enableSSE || !window.EventSource) {
                startPolling(NORMAL_INTERVAL);
                return;
            }
            
            try {
                eventSource = new EventSource(
                    `${wpAiWorkflowsShortcode.apiRoot}wp-ai-workflows/v1/stream?workflow_id=${workflowId}&session_id=${sessionId}`
                );
                
                eventSource.onmessage = function(event) {
                    try {
                        const data = JSON.parse(event.data);
                        if (data.status === 'update' && data.output) {
                            processData(data);
                        }
                    } catch (e) {
                        debugLogger.error('SSE message error:', e);
                    }
                };
                
                eventSource.onerror = function(error) {
                    debugLogger.log('SSE connection error, falling back to polling');
                    if (eventSource) {
                        eventSource.close();
                        eventSource = null;
                    }
                    startPolling(NORMAL_INTERVAL);
                };
                
                debugLogger.log('SSE connection established');
            } catch (e) {
                debugLogger.error('SSE initialization error:', e);
                startPolling(NORMAL_INTERVAL);
            }
        }

        function startPolling(interval = NORMAL_INTERVAL) {
            if (pollInterval) return;
            
            // Start with immediate fetch
            fetchOutput();
            pollInterval = setInterval(fetchOutput, interval);
            pollInterval._interval = interval; // Store the interval for reference
        }

        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }

        // Handle form submissions - unified handler for all form types
        function handleFormSubmission(formType) {
            debugLogger.log(`${formType} form submitted, clearing cache and showing loading...`);
            if (cache) cache.clear();
            ignoredStaleTimestamp = 0; // Clear any ignored stale data
            showLoadingState();

            // Restart polling if it was stopped
            if (config.stopOnSuccess && !pollInterval) {
                debugLogger.log('Restarting polling after form submission');
                if (config.enableSSE && !eventSource) {
                    initializeEventSource();
                } else {
                    startPolling(NORMAL_INTERVAL);
                }
            }
        }

        // Gravity Forms
        jQuery(document).on('submit', '.gform_wrapper form', function(e) {
            const form = jQuery(this);
            const possibleContainer = form.closest('.entry-content, .post-content, body').find(`#wp-ai-workflows-output-${workflowId}`);
            if (possibleContainer.length > 0) {
                handleFormSubmission('Gravity Forms');
            }
        });

        // WPForms - AJAX submit success event (official documentation)
        jQuery(window).on('wpformsAjaxSubmitSuccess', function(e, response) {
            debugLogger.log('WPForms AJAX submit success detected', response);
            // Check if our shortcode container exists on the page
            const shortcodeContainer = jQuery(`#wp-ai-workflows-output-${workflowId}`);
            if (shortcodeContainer.length > 0) {
                handleFormSubmission('WPForms');
            }
        });

        // Contact Form 7 - Mail sent event (official documentation)
        jQuery(document).on('wpcf7mailsent', function(event) {
            debugLogger.log('Contact Form 7 mail sent detected', event.detail);
            // Check if our shortcode container exists on the page
            const shortcodeContainer = jQuery(`#wp-ai-workflows-output-${workflowId}`);
            if (shortcodeContainer.length > 0) {
                handleFormSubmission('Contact Form 7');
            }
        });

        // Ninja Forms - Main submission event
        jQuery(document).on('nfFormSubmitResponse', function(event, response) {
            debugLogger.log('Ninja Forms submit response detected', response);
            // Check if our shortcode container exists on the page
            const shortcodeContainer = jQuery(`#wp-ai-workflows-output-${workflowId}`);
            if (shortcodeContainer.length > 0) {
                handleFormSubmission('Ninja Forms');
            }
        });

        // Elementor Forms - Submit success event
        jQuery(document).on('submit_success', function(event) {
            debugLogger.log('Elementor Forms submit success detected', event);
            // Check if our shortcode container exists on the page
            const shortcodeContainer = jQuery(`#wp-ai-workflows-output-${workflowId}`);
            if (shortcodeContainer.length > 0) {
                handleFormSubmission('Elementor Forms');
            }
        });

        // Generic form fallback (for custom forms or other form plugins)
        jQuery(document).on('submit', 'form', function(e) {
            const form = jQuery(this);
            // Skip if already handled by specific form handlers
            if (form.closest('.gform_wrapper, .wpforms-form, .wpcf7-form, .nf-form-cont, .elementor-form').length > 0) {
                return;
            }
            const possibleContainer = form.closest('.entry-content, .post-content, body').find(`#wp-ai-workflows-output-${workflowId}`);
            if (possibleContainer.length > 0) {
                handleFormSubmission('Generic');
            }
        });

        // Make functions available globally
        if (!window.wpAiWorkflows) {
            window.wpAiWorkflows = {};
        }
        
        window.wpAiWorkflows[workflowId] = {
            refresh: function() {
                if (cache) cache.clear();
                fetchOutput();
            },
            clearCache: function() {
                if (cache) cache.clear();
            },
            forceCheck: function() {
                debugLogger.log('Force checking for new data...');
                fetchOutput();
            },
            startPolling: function() {
                debugLogger.log('Manually starting polling...');
                if (!pollInterval) {
                    startPolling(NORMAL_INTERVAL);
                }
            },
            stopPolling: function() {
                debugLogger.log('Manually stopping polling...');
                stopPolling();
                if (eventSource) {
                    eventSource.close();
                    eventSource = null;
                }
            }
        };

        // Initialize - fetch once then start monitoring
        fetchOutput();
        
        // If clearOnRefresh is enabled, also clear the cache on page load
        if (config.clearOnRefresh && cache) {
            debugLogger.log('Clearing cache on page refresh (clearOnRefresh enabled)');
            cache.clear();
        }
        
        // Start continuous monitoring
        if (config.enableSSE) {
            initializeEventSource();
        } else {
            startPolling(NORMAL_INTERVAL);
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (eventSource) {
                eventSource.close();
            }
            stopPolling();
        });
    });
});