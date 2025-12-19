/**
 * Broxy Bot - Popup Script
 */

// DOM Elements
const elements = {
    statusIndicator: document.getElementById('status-indicator'),
    connectionStatus: document.getElementById('connection-status'),
    botId: document.getElementById('bot-id'),
    requestsCompleted: document.getElementById('requests-completed'),
    requestsFailed: document.getElementById('requests-failed'),
    serverUrl: document.getElementById('server-url'),
    saveConfig: document.getElementById('save-config'),
    connectBtn: document.getElementById('connect-btn'),
    disconnectBtn: document.getElementById('disconnect-btn')
};

// Update UI with status
function updateUI(status) {
    const isConnected = status.isConnected;

    // Update status indicator
    elements.statusIndicator.classList.toggle('connected', isConnected);
    elements.connectionStatus.textContent = isConnected ? 'Connected' : 'Disconnected';

    // Update bot ID
    elements.botId.textContent = status.botId || '-';

    // Update stats
    if (status.stats) {
        elements.requestsCompleted.textContent = status.stats.requestsCompleted || 0;
        elements.requestsFailed.textContent = status.stats.requestsFailed || 0;
    }

    // Update buttons
    elements.connectBtn.classList.toggle('hidden', isConnected);
    elements.disconnectBtn.classList.toggle('hidden', !isConnected);
}

// Load current configuration
async function loadConfig() {
    const config = await chrome.storage.local.get(['serverUrl']);
    elements.serverUrl.value = config.serverUrl || 'ws://localhost:9999';
}

// Get current status from background
async function refreshStatus() {
    try {
        const status = await chrome.runtime.sendMessage({ type: 'getStatus' });
        updateUI(status);
    } catch (error) {
        console.error('Failed to get status:', error);
    }
}

// Event listeners
elements.saveConfig.addEventListener('click', async () => {
    const config = {
        serverUrl: elements.serverUrl.value
    };

    await chrome.runtime.sendMessage({ type: 'updateConfig', config });
    elements.saveConfig.textContent = 'Saved!';
    setTimeout(() => {
        elements.saveConfig.textContent = 'Save Configuration';
    }, 1500);
});

elements.connectBtn.addEventListener('click', async () => {
    await chrome.runtime.sendMessage({ type: 'connect' });
    refreshStatus();
});

elements.disconnectBtn.addEventListener('click', async () => {
    await chrome.runtime.sendMessage({ type: 'disconnect' });
    refreshStatus();
});

// Listen for status updates from background
chrome.runtime.onMessage.addListener((message) => {
    if (message.type === 'status') {
        updateUI(message.data);
    }
});

// Initialize
loadConfig();
refreshStatus();

// Refresh status periodically
setInterval(refreshStatus, 2000);

