class WPAIWorkflowsChat {
    constructor(container) {
        this.container = container;
        this.workflowId = container.dataset.workflowId;
        this.sessionId = container.dataset.sessionId || null;
        this.config = window[`wpAiWorkflowsChat_${this.workflowId}`].config;
        this.apiUrl = window.wpAiWorkflowsSettings.apiUrl;
        this.nonce = window.wpAiWorkflowsSettings.nonce;
        
        // Action polling state
        this.hasPendingAction = false;
        this.pollTimer = null;
        
        this.initializeUI();
        this.setupEventListeners();
        this.loadChatHistory();
    }

    initializeUI() {
        // Create chat interface based on design settings
        const { design } = this.config;
        
        this.container.innerHTML = `
            <div class="chat-window" style="
                width: ${design.dimensions.width}px;
                height: ${design.dimensions.height}px;
                border-radius: ${design.dimensions.borderRadius}px;
                background: ${design.colors.background};
                font-family: ${design.typography.fontFamily};
                font-size: ${design.typography.fontSize}px;
            ">
                ${design.chatStyle.showHeader ? `
                    <div class="chat-header" style="background: ${design.colors.primary}">
                        ${design.chatStyle.headerText}
                    </div>
                ` : ''}
                <div class="chat-messages"></div>
                <div class="chat-input">
                    <input type="text" placeholder="${this.config.behavior.placeholderText || 'Type your message...'}">
                    <button>Send</button>
                </div>
            </div>
        `;
        
        // Add initial message if configured
        if (this.config.behavior.initialMessage) {
            this.addMessage('assistant', this.config.behavior.initialMessage);
        }
    }

    setupEventListeners() {
        const input = this.container.querySelector('input');
        const button = this.container.querySelector('button');
        
        button.addEventListener('click', () => this.sendMessage(input.value));
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.sendMessage(input.value);
            }
        });
    }

    async loadChatHistory() {
        if (!this.sessionId) {
            // Check for stored session ID in localStorage
            const storedId = localStorage.getItem(`wp_ai_chat_session_${this.workflowId}`);
            if (storedId) {
                this.sessionId = storedId;
            } else {
                return; // No history to load
            }
        }
        
        try {
            const response = await fetch(`${this.apiUrl}/chat-history?workflow_id=${this.workflowId}&session_id=${this.sessionId}`, {
                headers: {
                    'X-WP-Nonce': this.nonce
                }
            });
            
            const data = await response.json();
            if (data.success && data.history) {
                data.history.forEach(message => {
                    this.addMessage(message.role, message.content);
                });
            }
        } catch (error) {
            console.error('Error loading chat history:', error);
        }
    }

    async sendMessage(message) {
        if (!message.trim()) return;
        
        const input = this.container.querySelector('input');
        input.value = '';
        
        // Disable input and show loading state
        this.setTypingState(true);
        
        // Add user message to the chat
        this.addMessage('user', message);
        
        try {
            const response = await fetch(`${this.apiUrl}/chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.nonce
                },
                body: JSON.stringify({
                    workflow_id: this.workflowId,
                    message: message,
                    session_id: this.sessionId
                })
            });
            
            const data = await response.json();
            
            // Save session ID if provided
            if (data.session_id) {
                this.sessionId = data.session_id;
                localStorage.setItem(`wp_ai_chat_session_${this.workflowId}`, this.sessionId);
            }
            
            // Add assistant response to the chat
            this.addMessage('assistant', data.message);
            
            // Check if this is an action that will have a pending result
            if (data.type === 'action' && data.has_pending_result) {
                this.hasPendingAction = true;
                this.addPendingIndicator();
                this.startActionPolling();
            }
            
        } catch (error) {
            console.error('Error sending message:', error);
            this.addErrorMessage('Failed to send message. Please try again.');
        } finally {
            this.setTypingState(false);
        }
    }
    
    setTypingState(isTyping) {
        const input = this.container.querySelector('input');
        const button = this.container.querySelector('button');
        
        input.disabled = isTyping || this.hasPendingAction;
        button.disabled = isTyping || this.hasPendingAction;
        
        // Add or remove typing indicator
        const messagesContainer = this.container.querySelector('.chat-messages');
        let indicator = messagesContainer.querySelector('.typing-indicator');
        
        if (isTyping) {
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.className = 'typing-indicator';
                indicator.innerHTML = '<span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>';
                messagesContainer.appendChild(indicator);
            }
        } else if (indicator && !this.hasPendingAction) {
            indicator.remove();
        }
    }
    
    addPendingIndicator() {
        const messagesContainer = this.container.querySelector('.chat-messages');
        let indicator = messagesContainer.querySelector('.pending-indicator');
        
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'pending-indicator';
            indicator.innerHTML = 'Processing your request...';
            messagesContainer.appendChild(indicator);
        }
    }
    
    removePendingIndicator() {
        const indicator = this.container.querySelector('.pending-indicator');
        if (indicator) {
            indicator.remove();
        }
    }
    
    startActionPolling() {
        // Clear any existing poll timer
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
        }
        
        this.pollForActionResults();
    }
    
    async pollForActionResults() {
        if (!this.sessionId || !this.hasPendingAction) return;

        console.log(`[${new Date().toISOString()}] Checking for action results for session: ${sessionId}`);
        
        try {
            const response = await fetch(`${this.apiUrl}/chat-action-result?session_id=${this.sessionId}`, {
                headers: {
                    'X-WP-Nonce': this.nonce
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to check for action results');
            }
            
            const data = await response.json();
            console.log(`[${new Date().toISOString()}] Action result check response:`, data);
            
            if (data.success) {
                if (data.has_result && data.result) {
                    // We have a result! Add it to the chat
                    this.addMessage(data.result.role, data.result.content);
                    
                    // Reset action state
                    this.hasPendingAction = false;
                    this.removePendingIndicator();
                    this.enableInputs();
                    
                } else if (data.has_pending) {
                    // Still pending, poll again after a delay
                    this.pollTimer = setTimeout(() => this.pollForActionResults(), 2000);
                } else {
                    // No pending execution or result, stop polling
                    this.hasPendingAction = false;
                    this.removePendingIndicator();
                    this.enableInputs();
                }
            }
        } catch (error) {
            console.error('Error polling for action results:', error);
            // Even on error, we'll try again after a longer delay
            this.pollTimer = setTimeout(() => this.pollForActionResults(), 5000);
        }
    }
    
    enableInputs() {
        const input = this.container.querySelector('input');
        const button = this.container.querySelector('button');
        
        input.disabled = false;
        button.disabled = false;
    }

    addMessage(role, content) {
        const messagesContainer = this.container.querySelector('.chat-messages');
        const messageDiv = document.createElement('div');
        messageDiv.className = `chat-message ${role}`;
        
        // Sanitize and format the content
        // In a real implementation, you'd use DOMPurify or similar
        messageDiv.innerHTML = this.formatMessage(content);
        
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    addErrorMessage(text) {
        const messagesContainer = this.container.querySelector('.chat-messages');
        const messageDiv = document.createElement('div');
        messageDiv.className = 'error-message';
        messageDiv.textContent = text;
        
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            messageDiv.remove();
        }, 5000);
    }
    
    formatMessage(content) {
        // Very basic markdown-like formatting
        // For a real implementation, use a proper markdown parser
        return content
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\n/g, '<br>');
    }
}

// Initialize chat instances
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.wp-ai-workflows-chat').forEach(container => {
        new WPAIWorkflowsChat(container);
    });
});