import React, { useState, useRef, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Send, X, User, Bot, Loader2, ArrowRight } from 'lucide-react';

// Both chat surfaces use Luvep's server-side Claude connector.
const CHAT_ENDPOINT = '/api/chat';
const MESSAGES_KEY = 'epathways_chat_messages';
const WELCOME_MESSAGE = {
    role: 'assistant',
    content: 'Hi, I\'m Pathy — your EP guide. How can I help you navigate your New Zealand journey today?',
};

// Load the persisted message log. Survives Inertia page navigations (the
// ChatBot remounts on every page) so the conversation doesn't reset when
// the visitor clicks around the site. Falls back to the welcome message
// when there's no saved history or storage is blocked.
function loadMessages() {
    try {
        const raw = window.localStorage.getItem(MESSAGES_KEY);
        if (!raw) return [WELCOME_MESSAGE];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) && parsed.length > 0 ? parsed : [WELCOME_MESSAGE];
    } catch {
        return [WELCOME_MESSAGE];
    }
}

function saveMessages(messages) {
    try {
        window.localStorage.setItem(MESSAGES_KEY, JSON.stringify(messages));
    } catch { /* storage blocked — ignore */ }
}

// Pull the assistant reply from the local API response.
// Accept the server reply shape and tolerate older stored responses that end up
// shaped differently, so check the common keys then fall back to JSON.
function extractAssistantReply(data) {
    if (data == null) return null;
    if (typeof data === 'string') return data;
    if (Array.isArray(data)) return extractAssistantReply(data[0]);
    return (
        data.output ??
        data.text ??
        data.message ??
        data.reply ??
        data.response ??
        data?.choices?.[0]?.message?.content ??
        null
    );
}

// The chat widget renders plain text — it does NOT do Markdown. Even though
// instructions request plain text, models can still emit Markdown and
// the visible result ("**bold**", "* item") looks broken. This defensively
// strips the common Markdown symbols so the reader sees clean text. Bullet
// `* ` at line starts is converted to `- ` to keep list semantics.
function sanitizeMarkdown(text) {
    if (!text || typeof text !== 'string') return text || '';
    return text
        // Normalise line endings
        .replace(/\r\n/g, '\n')
        // Strip ATX headings (## Heading)
        .replace(/^[ \t]*#{1,6}[ \t]+/gm, '')
        // Bold **text** or __text__ -> text
        .replace(/\*\*([^*]+?)\*\*/g, '$1')
        .replace(/__([^_]+?)__/g, '$1')
        // Inline code `text` -> text
        .replace(/`([^`]+?)`/g, '$1')
        // Italic *text* / _text_ -> text, but only when wrapping a word
        // (don't eat bullet `* item` or arithmetic underscores in slugs).
        .replace(/(^|[\s(])\*(\S[^*\n]*?\S|\S)\*(?=[\s.,!?)\]:;]|$)/g, '$1$2')
        .replace(/(^|[\s(])_(\S[^_\n]*?\S|\S)_(?=[\s.,!?)\]:;]|$)/g, '$1$2')
        // Convert bullet "* item" or "+ item" at line start to "- item"
        .replace(/^[ \t]*[*+][ \t]+/gm, '- ')
        // Collapse 3+ blank lines down to 2
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

const ChatBot = ({ isOpen, onClose }) => {
    // Lazy initialiser reads the saved log so the conversation survives
    // Inertia page navigations (which remount this component).
    const [messages, setMessages] = useState(loadMessages);
    const [input, setInput] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const messagesEndRef = useRef(null);

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
    };

    useEffect(() => {
        if (isOpen) {
            scrollToBottom();
        }
    }, [messages, isOpen]);

    // Persist the conversation so it's restored on the next mount.
    useEffect(() => {
        saveMessages(messages);
    }, [messages]);

    const handleSend = async (e) => {
        e.preventDefault();
        if (!input.trim() || isLoading) return;

        const userMsg = { role: 'user', content: input };
        const currentInput = input;
        setMessages(prev => [...prev, userMsg]);
        setInput('');
        setIsLoading(true);

        try {
            const res = await fetch(CHAT_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    message: currentInput,
                    history: messages.filter(m => ['user', 'assistant'].includes(m.role) && typeof m.content === 'string').slice(-12).map(m => ({role: m.role, content: m.content.slice(0, 2000)})),
                }),
            });

            if (!res.ok) {
                const failure = await res.json().catch(() => ({}));
                throw new Error(failure.message || (res.status === 429 ? 'Too many messages. Please wait a minute and try again.' : 'Pathy is unavailable. Please contact EP through /booking.'));
            }

            const raw = await res.text();
            let data = null;
            try { data = JSON.parse(raw); } catch { data = raw; }

            const rawReply = extractAssistantReply(data)
                || 'Sorry, I did not get a response. Please try again.';
            const reply = sanitizeMarkdown(rawReply);

            setMessages(prev => [...prev, { role: 'assistant', content: reply }]);
        } catch (error) {
            console.error('Chat error:', error);
            setMessages(prev => [...prev, {
                role: 'assistant',
                content: error.message || 'Pathy is unavailable. Please contact EP through /booking.',
            }]);
        } finally {
            setIsLoading(false);
        }
    };

    const quickActions = [
        { label: 'Start Assessment', link: '/free-assessment' },
        { label: 'Book Consultation', link: '/booking' },
        { label: 'Explore Programs', link: '/programs' }
    ];

    return (
        <AnimatePresence>
            {isOpen && (
                <motion.div
                    initial={{ opacity: 0, scale: 0.9, y: 30, transformOrigin: 'bottom right' }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    exit={{ opacity: 0, scale: 0.9, y: 30 }}
                    transition={{ type: 'spring', damping: 25, stiffness: 300 }}
                    className="fixed bottom-24 right-4 md:right-6 w-[350px] md:w-[420px] h-[600px] bg-white rounded-[2rem] shadow-[0_25px_80px_rgba(0,0,0,0.2)] flex flex-col overflow-hidden z-[60] border border-slate-200 font-urbanist"
                >
                    {/* Header: Dark Gray Premium */}
                    <div className="bg-slate-900 p-5 flex items-center justify-between relative overflow-hidden group">
                        {/* Subtle background glow */}
                        <div className="absolute -top-10 -left-10 w-32 h-32 bg-[#436235]/20 blur-[50px] rounded-full group-hover:bg-[#436235]/30 transition-all duration-700"></div>
                        
                        <div className="flex items-center gap-4 relative z-10">
                            <div className="relative">
                                <div className="w-11 h-11 rounded-full bg-gradient-to-br from-[#436235] to-[#2d4224] flex items-center justify-center text-white shadow-lg border border-white/20">
                                    <Bot size={22} strokeWidth={1.5} />
                                </div>
                                <span className="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-green-500 border-2 border-slate-900 animate-pulse"></span>
                            </div>
                            <div>
                                <h3 className="text-white font-black text-sm tracking-tight">Pathy</h3>
                                <p className="text-[10px] text-white/50 font-medium tracking-wider">Your EP guide · AI assistant</p>
                            </div>
                        </div>
                        <button 
                            onClick={onClose}
                            className="text-white/30 hover:text-white transition-all p-2 bg-white/5 hover:bg-white/10 rounded-full"
                        >
                            <X size={18} />
                        </button>
                    </div>

                    {/* Messages Area: White Background */}
                    <div className="flex-1 overflow-y-auto p-6 space-y-6 scrollbar-none bg-white">
                        {messages.map((msg, idx) => (
                            <motion.div 
                                initial={{ opacity: 0, y: 10, scale: 0.95 }}
                                animate={{ opacity: 1, y: 0, scale: 1 }}
                                transition={{ duration: 0.4, delay: 0.1 }}
                                key={idx} 
                                className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                            >
                                <div className={`max-w-[85%] flex gap-3 ${msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'}`}>
                                    <div className={`p-4 rounded-3xl text-[13px] leading-relaxed shadow-sm whitespace-pre-line break-words ${
                                        msg.role === 'user'
                                            ? 'bg-[#436235] text-white rounded-tr-none shadow-[0_8px_16px_rgba(67,98,53,0.2)]'
                                            : 'bg-slate-100 text-slate-700 rounded-tl-none border border-slate-200'
                                    }`}>
                                        {msg.content}
                                    </div>
                                </div>
                            </motion.div>
                        ))}
                        
                        {isLoading && (
                            <motion.div 
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                className="flex justify-start"
                            >
                                <div className="bg-slate-100 p-4 rounded-3xl rounded-tl-none border border-slate-200 flex items-center gap-2">
                                    <span className="w-1.5 h-1.5 bg-slate-400 rounded-full animate-bounce"></span>
                                    <span className="w-1.5 h-1.5 bg-slate-400 rounded-full animate-bounce [animation-delay:0.2s]"></span>
                                    <span className="w-1.5 h-1.5 bg-slate-400 rounded-full animate-bounce [animation-delay:0.4s]"></span>
                                </div>
                            </motion.div>
                        )}
                        <div ref={messagesEndRef} />
                    </div>

                    {/* Quick Actions Footer */}
                    <div className="px-6 pb-2 bg-white">
                        {messages.length === 1 && !isLoading && (
                            <div className="flex flex-wrap gap-2">
                                {quickActions.map((action, i) => (
                                    <motion.a 
                                        key={i}
                                        initial={{ opacity: 0, y: 10 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: 0.5 + (i * 0.1) }}
                                        whileHover={{ scale: 1.05, backgroundColor: "rgba(0, 0, 0, 0.05)" }}
                                        href={action.link}
                                        target={action.link.startsWith('http') ? '_blank' : '_self'}
                                        className="text-[10px] bg-slate-50 text-slate-500 border border-slate-200 px-4 py-2 rounded-full font-bold uppercase tracking-wider transition-all flex items-center gap-2"
                                    >
                                        {action.label} <ArrowRight size={12} className="opacity-40" />
                                    </motion.a>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Input Field: White/Gray Minimalist */}
                    <form onSubmit={handleSend} className="p-6 bg-white border-t border-slate-100">
                        <div className="relative group/input flex items-center">
                            <input
                                type="text"
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                placeholder="Pave your path..."
                                className="w-full bg-slate-50 border border-slate-200 rounded-2xl pl-5 pr-14 py-4 text-sm text-slate-800 focus:outline-none focus:border-[#436235]/40 focus:bg-white transition-all placeholder:text-slate-400 shadow-sm"
                            />
                            <button
                                type="submit"
                                disabled={!input.trim() || isLoading}
                                className="absolute right-2 w-10 h-10 rounded-xl bg-[#436235] flex items-center justify-center text-white disabled:opacity-20 disabled:grayscale transition-all hover:shadow-[0_4px_12px_rgba(67,98,53,0.3)] active:scale-90"
                            >
                                <Send size={16} />
                            </button>
                        </div>
                    </form>
                </motion.div>
            )}
        </AnimatePresence>
    );
};

export default ChatBot;
