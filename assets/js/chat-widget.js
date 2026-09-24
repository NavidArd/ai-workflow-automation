import React, { useState, useEffect, useRef } from 'react';

const ChatWidget = ({ workflowId, config }) => {
    const [messages, setMessages] = useState([]);
    const [inputValue, setInputValue] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [sessionId, setSessionId] = useState(null);
    const [isTyping, setIsTyping] = useState(false);
    const messagesEndRef = useRef(null);
    const eventSourceRef = useRef(null);

    useEffect(() => {
        // Load chat history
        if (sessionId) {
            fetchChatHistory();
        } else {
            // Check for existing session ID in localStorage
            const storedSessionId = localStorage.getItem(`wp_ai_chat_session_${workflowId}`);
            if (storedSessionId) {
                setSessionId(storedSessionId);
            }
        }

        return () => {
            if (eventSourceRef.current) {
                eventSourceRef.current.close();
            }
        };
    }, [sessionId, workflowId]);

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(scrollToBottom, [messages]);

    const fetchChatHistory = async () => {
        try {
            const response = await fetch(
                `${wpAiWorkflowsChat.apiUrl}/chat-history?workflow_id=${workflowId}&session_id=${sessionId}`,
                {
                    headers: {
                        'X-WP-Nonce': wpAiWorkflowsChat.nonce
                    }
                }
            );
            const data = await response.json();
            if (data.success && data.history) {
                setMessages(data.history);
            }
        } catch (error) {
            console.error('Error fetching chat history:', error);
        }
    };

    const handleSendMessage = async () => {
        if (!inputValue.trim()) return;

        const userMessage = inputValue;
        setInputValue('');
        setMessages(prev => [...prev, { role: 'user', content: userMessage }]);
        setIsTyping(true);

        if (config.behavior.soundEffects) {
            playSound('send');
        }

        try {
            // Close any existing connection
            if (eventSourceRef.current) {
                eventSourceRef.current.close();
            }

            // Create new EventSource connection
            const url = new URL(`${wpAiWorkflowsChat.apiUrl}/chat/stream`);
            url.searchParams.append('workflow_id', workflowId);
            url.searchParams.append('message', userMessage);
            if (sessionId) {
                url.searchParams.append('session_id', sessionId);
            }

            eventSourceRef.current = new EventSource(url.toString());
            let fullResponse = '';

            eventSourceRef.current.onmessage = (event) => {
                if (event.data === '[DONE]') {
                    setIsTyping(false);
                    eventSourceRef.current.close();
                    if (config.behavior.soundEffects) {
                        playSound('receive');
                    }
                } else {
                    try {
                        const data = JSON.parse(event.data);
                        if (data.content) {
                            fullResponse += data.content;
                            setMessages(prev => {
                                const newMessages = [...prev];
                                const lastMessage = newMessages[newMessages.length - 1];
                                if (lastMessage && lastMessage.role === 'assistant') {
                                    lastMessage.content = fullResponse;
                                } else {
                                    newMessages.push({ role: 'assistant', content: fullResponse });
                                }
                                return newMessages;
                            });
                        }
                        if (data.session_id && !sessionId) {
                            setSessionId(data.session_id);
                            localStorage.setItem(`wp_ai_chat_session_${workflowId}`, data.session_id);
                        }
                    } catch (error) {
                        console.error('Error parsing SSE message:', error);
                    }
                }
            };

            eventSourceRef.current.onerror = (error) => {
                console.error('SSE Error:', error);
                eventSourceRef.current.close();
                setIsTyping(false);
            };

        } catch (error) {
            console.error('Error sending message:', error);
            setIsTyping(false);
        }
    };

    const playSound = (type) => {
        const audio = new Audio(`${wpAiWorkflowsChat.assetsUrl}/sounds/${type}.mp3`);
        audio.volume = 0.5;
        audio.play().catch(error => console.error('Error playing sound:', error));
    };

    const handleKeyPress = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendMessage();
        }
    };

    if (!isOpen) {
        return (
            <button 
                className={`chat-launcher ${config.design.position}`}
                onClick={() => setIsOpen(true)}
                aria-label="Open chat"
            >
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
            </button>
        );
    }

    return (
        <div 
            className={`wp-ai-workflows-chat-widget ${config.design.theme} ${config.design.position}`}
            style={{
                width: config.design.dimensions.width,
                height: config.design.dimensions.height,
                borderRadius: config.design.dimensions.borderRadius,
                ...(config.design.theme === 'custom' ? {
                    '--chat-primary': config.design.colors.primary,
                    '--chat-bg': config.design.colors.background,
                    '--chat-text': config.design.colors.text,
                } : {})
            }}
        >
            <div className="chat-header">
                <button 
                    className="close-button"
                    onClick={() => setIsOpen(false)}
                    aria-label="Close chat"
                >×</button>
                {config.behavior.soundEffects && (
                    <button 
                        className="sound-toggle"
                        onClick={() => {/* Toggle sound */}}
                        aria-label="Toggle sound"
                    >
                        🔊
                    </button>
                )}
                {messages.length === 0 && config.behavior.initialMessage && (
                    <div className="initial-message">{config.behavior.initialMessage}</div>
                )}
            </div>

            <div className="chat-messages">
                {messages.map((message, index) => (
                    <div key={index} className={`chat-message ${message.role}`}>
                        <div className="message-content">
                            {message.content}
                        </div>
                    </div>
                ))}
                {isTyping && (
                    <div className="typing-indicator">
                        <span className="typing-dot"></span>
                        <span className="typing-dot"></span>
                        <span className="typing-dot"></span>
                    </div>
                )}
                <div ref={messagesEndRef} />
            </div>

            <div className="chat-input">
                <input
                    type="text"
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                    onKeyPress={handleKeyPress}
                    placeholder={config.behavior.placeholderText}
                    disabled={isTyping}
                />
                <button 
                    onClick={handleSendMessage}
                    disabled={isTyping || !inputValue.trim()}
                >
                    Send
                </button>
            </div>
        </div>
    );
};

// Initialize chat widgets
document.addEventListener('DOMContentLoaded', function() {
    const containers = document.querySelectorAll('.wp-ai-workflows-chat-container');
    containers.forEach(container => {
        const workflowId = container.dataset.workflowId;
        const config = JSON.parse(atob(container.dataset.config));
        
        const root = ReactDOM.createRoot(container);
        root.render(
            <ChatWidget 
                workflowId={workflowId}
                config={config}
            />
        );
    });
});

export default ChatWidget;
