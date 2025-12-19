/**
 * Broxy Bot - Background Service Worker
 * Maintains WebSocket connection to control server and executes HTTP requests
 * by opening actual browser tabs to load pages
 */

// Configuration (will be loaded from storage)
let config = {
    serverUrl: 'ws://localhost:9999',
    heartbeatInterval: 25000, // 25 seconds
    reconnectDelay: 5000, // 5 seconds
    maxReconnectAttempts: 10,
    pageLoadTimeout: 30000 // 30 seconds timeout for page load
};

// State
let ws = null;
let botId = null;
let isConnected = false;
let reconnectAttempts = 0;
let stats = {
    requestsCompleted: 0,
    requestsFailed: 0,
    connectedAt: null,
    lastActivity: null
};

// Track pending tab requests: tabId -> { requestId, url, timeoutId }
const pendingTabs = new Map();

// Load configuration from storage
async function loadConfig() {
    const stored = await chrome.storage.local.get(['serverUrl']);
    if (stored.serverUrl) config.serverUrl = stored.serverUrl;
}

// Save configuration to storage
async function saveConfig(newConfig) {
    config = { ...config, ...newConfig };
    await chrome.storage.local.set({
        serverUrl: config.serverUrl
    });
}

// Connect to control server
async function connect() {
    if (ws && ws.readyState === WebSocket.OPEN) {
        console.log('[Broxy] Already connected');
        return;
    }

    await loadConfig();

    console.log(`[Broxy] Connecting to ${config.serverUrl}...`);

    try {
        ws = new WebSocket(config.serverUrl);

        ws.onopen = onOpen;
        ws.onmessage = onMessage;
        ws.onclose = onClose;
        ws.onerror = onError;
    } catch (error) {
        console.error('[Broxy] Connection error:', error);
        scheduleReconnect();
    }
}

// WebSocket event handlers
function onOpen() {
    console.log('[Broxy] Connected to control server');
    reconnectAttempts = 0;

    // Send authentication
    send({
        type: 'auth',
        user_agent: navigator.userAgent,
        browser: getBrowserInfo()
    });
}

function onMessage(event) {
    try {
        const message = JSON.parse(event.data);
        handleMessage(message);
    } catch (error) {
        console.error('[Broxy] Failed to parse message:', error);
    }
}

function onClose(event) {
    console.log(`[Broxy] Disconnected: ${event.code} ${event.reason}`);
    cleanup();
    scheduleReconnect();
}

function onError(error) {
    console.error('[Broxy] WebSocket error:', error);
}

// Message handling
function handleMessage(message) {
    stats.lastActivity = Date.now();

    switch (message.type) {
        case 'auth_success':
            handleAuthSuccess(message);
            break;
        case 'auth_failed':
            handleAuthFailed(message);
            break;
        case 'ping':
            handlePing();
            break;
        case 'request':
            handleRequest(message);
            break;
        default:
            console.warn('[Broxy] Unknown message type:', message.type);
    }
}

function handleAuthSuccess(message) {
    botId = message.bot_id;
    isConnected = true;
    stats.connectedAt = Date.now();

    // Update heartbeat interval from server
    if (message.heartbeat_interval) {
        config.heartbeatInterval = message.heartbeat_interval;
    }

    console.log(`[Broxy] Authenticated as ${botId}`);
    startHeartbeat();
    broadcastStatus();
}

function handleAuthFailed(message) {
    console.error('[Broxy] Authentication failed:', message.error);
    ws.close();
}

function handlePing() {
    send({ type: 'pong' });
}

async function handleRequest(message) {
    const { request_id, url } = message;

    console.log(`[Broxy] Opening tab for request ${request_id}: ${url}`);

    try {
        // Create a new tab with the target URL
        const tab = await chrome.tabs.create({
            url: url,
            active: false // Open in background
        });

        // Set up timeout
        const timeoutId = setTimeout(() => {
            handleTabTimeout(tab.id, request_id);
        }, config.pageLoadTimeout);

        // Track this pending request
        pendingTabs.set(tab.id, {
            requestId: request_id,
            url: url,
            timeoutId: timeoutId
        });

        console.log(`[Broxy] Created tab ${tab.id} for request ${request_id}`);

    } catch (error) {
        sendErrorResponse(request_id, error.message);
    }
}

// Handle tab load completion
chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
    // Only process if this is a tracked tab and it's fully loaded
    if (!pendingTabs.has(tabId) || changeInfo.status !== 'complete') {
        return;
    }

    const pending = pendingTabs.get(tabId);
    console.log(`[Broxy] Tab ${tabId} loaded for request ${pending.requestId}`);

    // Clear the timeout
    clearTimeout(pending.timeoutId);

    // Give the page a moment to finish any JS execution
    setTimeout(() => {
        captureTabContent(tabId, pending.requestId);
    }, 500);
});

// Capture page content from tab
async function captureTabContent(tabId, requestId) {
    try {
        // Inject script to capture page content
        const results = await chrome.scripting.executeScript({
            target: { tabId: tabId },
            func: () => {
                return {
                    html: document.documentElement.outerHTML,
                    title: document.title,
                    url: window.location.href
                };
            }
        });

        const content = results[0]?.result;

        if (content) {
            send({
                type: 'response',
                request_id: requestId,
                status: 200,
                headers: {
                    'content-type': 'text/html; charset=utf-8',
                    'x-broxy-title': content.title,
                    'x-broxy-final-url': content.url
                },
                body: content.html
            });

            stats.requestsCompleted++;
            console.log(`[Broxy] Request ${requestId} completed successfully`);
        } else {
            throw new Error('Failed to capture page content');
        }

    } catch (error) {
        sendErrorResponse(requestId, error.message);
    } finally {
        // Clean up: close tab and remove from tracking
        cleanupTab(tabId);
    }

    broadcastStatus();
}

// Handle tab timeout
function handleTabTimeout(tabId, requestId) {
    console.error(`[Broxy] Request ${requestId} timed out`);
    sendErrorResponse(requestId, 'Page load timeout');
    cleanupTab(tabId);
    broadcastStatus();
}

// Send error response
function sendErrorResponse(requestId, errorMessage) {
    send({
        type: 'response',
        request_id: requestId,
        status: 500,
        headers: {},
        body: errorMessage,
        error: errorMessage
    });
    stats.requestsFailed++;
}

// Clean up tab
function cleanupTab(tabId) {
    const pending = pendingTabs.get(tabId);
    if (pending) {
        clearTimeout(pending.timeoutId);
        pendingTabs.delete(tabId);
    }

    // Close the tab
    chrome.tabs.remove(tabId).catch(() => {
        // Tab might already be closed, ignore error
    });
}

// Handle tab closed unexpectedly
chrome.tabs.onRemoved.addListener((tabId) => {
    if (pendingTabs.has(tabId)) {
        const pending = pendingTabs.get(tabId);
        console.error(`[Broxy] Tab ${tabId} closed unexpectedly for request ${pending.requestId}`);
        sendErrorResponse(pending.requestId, 'Tab closed unexpectedly');
        clearTimeout(pending.timeoutId);
        pendingTabs.delete(tabId);
        broadcastStatus();
    }
});

// Heartbeat management
function startHeartbeat() {
    stopHeartbeat();

    // Use chrome.alarms for service worker persistence
    chrome.alarms.create('heartbeat', {
        periodInMinutes: config.heartbeatInterval / 60000
    });
}

function stopHeartbeat() {
    chrome.alarms.clear('heartbeat');
}

// Handle alarms
chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === 'heartbeat') {
        if (ws && ws.readyState === WebSocket.OPEN) {
            send({ type: 'pong' });
        } else {
            connect();
        }
    }
});

// Reconnection logic
function scheduleReconnect() {
    if (reconnectAttempts >= config.maxReconnectAttempts) {
        console.error('[Broxy] Max reconnection attempts reached');
        return;
    }

    reconnectAttempts++;
    const delay = config.reconnectDelay * Math.min(reconnectAttempts, 5);

    console.log(`[Broxy] Reconnecting in ${delay}ms (attempt ${reconnectAttempts})`);

    setTimeout(connect, delay);
}

// Cleanup on disconnect
function cleanup() {
    isConnected = false;
    botId = null;
    stopHeartbeat();
}

// Send message to server
function send(data) {
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(data));
    }
}

// Get browser information
function getBrowserInfo() {
    const ua = navigator.userAgent;
    if (ua.includes('Firefox')) return 'Firefox';
    if (ua.includes('Chrome')) return 'Chrome';
    if (ua.includes('Safari')) return 'Safari';
    if (ua.includes('Edge')) return 'Edge';
    return 'Unknown';
}

// Broadcast status to popup
function broadcastStatus() {
    chrome.runtime.sendMessage({
        type: 'status',
        data: getStatus()
    }).catch(() => {
        // Popup might not be open, ignore error
    });
}

// Get current status
function getStatus() {
    return {
        isConnected,
        botId,
        serverUrl: config.serverUrl,
        stats: { ...stats }
    };
}

// Message handling from popup
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    switch (message.type) {
        case 'getStatus':
            sendResponse(getStatus());
            break;
        case 'connect':
            connect();
            sendResponse({ success: true });
            break;
        case 'disconnect':
            if (ws) ws.close();
            sendResponse({ success: true });
            break;
        case 'updateConfig':
            saveConfig(message.config).then(() => {
                sendResponse({ success: true });
            });
            return true; // Keep channel open for async response
    }
});

// Auto-connect on startup
connect();

console.log('[Broxy] Bot service worker initialized');

