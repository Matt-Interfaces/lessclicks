<script>
import { onMount, onDestroy } from "svelte";
import { fade } from "svelte/transition";
import { Chart } from 'chart.js/auto';
import LessclicksInput from './components/LessclicksInput.svelte';

// Global WordPress data
const wpData = window.lessclicksAdmin || {};
const restUrl = wpData.restUrl || '/wp-json/lessclicks/v1/';
const nonce = wpData.nonce || '';
const pluginUrl = wpData.pluginUrl || '';
const version = wpData.version || '';
const matomoDetected = wpData.matomoDetected || { installed: false };
const i18n = wpData.i18n || {};
// WordPress returns locales like en_US; toLocaleDateString requires en-US.
const locale = (wpData.locale || 'en-US').replace(/_/g, '-');

// State
let settings = {
    matomo_url: '',
    site_id: 1,
    token_auth: '',
    chart_type: 'line',
    chart_color: '#2271b1',
    date_range: 30,
    auto_detect_matomo: true
};

let loading = true;
let saving = false;
let testing = false;
let autoConnecting = false;
let connectionStatus = null; // null, 'success', 'error'
let connectionMessage = '';
let toast = null;
let activeTab = 'settings';
let showToken = false;
let autoConnected = false;

// Visually mask WordPress-auth mode with a generated placeholder token.
const wpAuthPlaceholder = generatePlaceholderToken();

// Preview data
let previewLoading = false;
let previewError = null;
let previewData = [];
let previewCanvas;
let previewChart = null;

// Helpers
function generatePlaceholderToken() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let token = 'wpauth_';
    for (let i = 0; i < 32; i++) {
        token += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return token;
}

// Mask token for display
function maskToken(token) {
    if (!token || token.length < 8) return '••••••••';
    return token.substring(0, 4) + '••••••••' + token.substring(token.length - 4);
}

const chartTypes = [
    { value: 'line', label: i18n.line || 'Line chart', iconClass: 'dashicons-chart-line' },
    { value: 'bar', label: i18n.bar || 'Bar chart', iconClass: 'dashicons-chart-bar' },
    { value: 'pie', label: i18n.pie || 'Pie chart', iconClass: 'dashicons-chart-pie' }
];

const dateRanges = [
    { value: 7, label: i18n.last7days || 'Last 7 days' },
    { value: 14, label: i18n.last14days || 'Last 14 days' },
    { value: 30, label: i18n.last30days || 'Last 30 days' },
    { value: 60, label: i18n.last60days || 'Last 60 days' },
    { value: 90, label: i18n.last90days || 'Last 90 days' }
];

// Persist helper (without UI feedback)
async function persistSettings() {
    try {
        const response = await fetch(`${restUrl}settings`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify(settings)
        });
        return response.ok;
    } catch (error) {
        console.error('Save error:', error);
        return false;
    }
}

// API Funktionen
async function fetchSettings() {
    try {
        const response = await fetch(`${restUrl}settings`, {
            headers: {
                'X-WP-Nonce': nonce
            }
        });

        if (response.ok) {
            const data = await response.json();
            settings = { ...settings, ...data };

            // Determine header status once after loading
            await initializeConnectionState();
        }
    } catch (error) {
        console.error('Settings load error:', error);
    } finally {
        loading = false;
    }
}

async function initializeConnectionState() {
    // Matomo for WordPress automatisch verbinden
    if (matomoDetected.installed && settings.auto_detect_matomo) {
        await useMatomoWP(true);
        return;
    }

    // Manuelle Konfiguration testen
    if (settings.matomo_url && (settings.token_auth || settings.auto_detect_matomo)) {
        await testConnection(true);
        return;
    }

    // Not configured yet
    connectionStatus = null;
    connectionMessage = '';
}

async function saveSettings() {
    if (saving) return;
    saving = true;

    try {
        const ok = await persistSettings();

        if (ok) {
            showToast(i18n.saveSuccess || 'Settings saved.', 'success');
        } else {
            showToast(i18n.saveError || 'Could not save settings.', 'error');
        }
    } finally {
        saving = false;
    }
}

// Reset connection test status
function resetConnectionTest() {
    connectionStatus = null;
    connectionMessage = '';
}

// Preview: load real data from the analytics or device endpoint
function getPreviewDateRange() {
    const days = parseInt(settings.date_range, 10) || 30;
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - days);
    return {
        start: start.toISOString().slice(0, 10),
        end: end.toISOString().slice(0, 10)
    };
}

async function loadPreviewData() {
    if (previewLoading) return;
    previewLoading = true;
    previewError = null;
    previewData = [];

    const dates = getPreviewDateRange();
    const dateParam = `${dates.start},${dates.end}`;

    try {
        const isPie = settings.chart_type === 'pie';
        const endpoint = isPie ? `${restUrl}devices?date=${dateParam}` : `${restUrl}analytics?period=day&date=${dateParam}`;
        // Short artificial delay prevents flickering on fast load times
        await new Promise(resolve => setTimeout(resolve, 300));
        const response = await fetch(endpoint, {
            headers: { 'X-WP-Nonce': nonce }
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || i18n.previewError || 'Could not load the preview.');
        }

        const data = await response.json();

        if (isPie) {
            previewData = Array.isArray(data) ? data : [];
        } else {
            previewData = (Array.isArray(data) ? data : []).map(day => ({
                label: day.date || '',
                value: day.nb_visits || day.nb_uniq_visitors || 0,
                nb_pageviews: day.nb_pageviews || day.nb_actions || 0,
                bounce_rate: day.bounce_rate,
                avg_time_on_site: day.avg_time_on_site || 0
            }));
        }
    } catch (err) {
        console.error('Preview error:', err);
        previewError = err.message;
        previewData = [];
    } finally {
        previewLoading = false;
    }
}

// Auto-reload preview when changes occur
$: if (settings.chart_type !== undefined && settings.date_range !== undefined) {
    loadPreviewData();
}

// Limit selected data points to a maximum of 7
$: previewSlice = previewData.slice(-7);

// Aggregated preview stats for the metrics card

// Format preview date labels
function formatPreviewLabel(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return dateStr;
    return date.toLocaleDateString(locale, { day: '2-digit', month: '2-digit' });
}

function safeHex(color) {
    if (!color || color[0] !== '#') return '#2271b1';
    return color.length === 4 ? '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3] : color;
}

function createPreviewGradient(ctx, color) {
    const safeColor = safeHex(color);
    const gradient = ctx.createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, safeColor + '50');
    gradient.addColorStop(1, safeColor + '08');
    return gradient;
}

function generatePreviewColors(length, baseColor) {
    const safeBase = safeHex(baseColor);
    const colors = [];
    for (let i = 0; i < length; i++) {
        const opacity = 0.55 + (i / Math.max(length, 1)) * 0.45;
        colors.push(safeBase + Math.round(opacity * 255).toString(16).padStart(2, '0'));
    }
    return colors;
}

function createPreviewChart() {
    if (!previewCanvas || previewLoading || previewError || !previewData.length) return;

    const ctx = previewCanvas.getContext('2d');
    if (previewChart) {
        previewChart.destroy();
        previewChart = null;
    }

    const isPie = settings.chart_type === 'pie';
    const labels = isPie
        ? previewData.map(d => d.label)
        : previewSlice.map(d => formatPreviewLabel(d.label));
    const data = isPie
        ? previewData.map(d => d.value)
        : previewSlice.map(d => d.value);

    const datasetLabel = isPie ? (i18n.osFamilies || 'Operating system families') : (i18n.visits || 'Visits');

    previewChart = new Chart(ctx, {
        type: settings.chart_type,
        data: {
            labels,
            datasets: [{
                label: datasetLabel,
                data,
                borderColor: isPie ? '#ffffff' : settings.chart_color,
                backgroundColor: settings.chart_type === 'line'
                    ? createPreviewGradient(ctx, settings.chart_color)
                    : generatePreviewColors(data.length, settings.chart_color),
                tension: 0.4,
                fill: settings.chart_type === 'line',
                borderWidth: settings.chart_type === 'line' ? 3 : 0,
                pointRadius: settings.chart_type === 'line' ? 4 : 0,
                pointBackgroundColor: settings.chart_color,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                borderRadius: settings.chart_type === 'bar' ? 6 : 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 700,
                easing: 'easeOutQuart'
            },
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: isPie,
                    position: 'bottom',
                    labels: {
                        padding: 12,
                        usePointStyle: true,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 12,
                    titleFont: { size: 12, weight: '600' },
                    bodyFont: { size: 12 },
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed.y !== undefined ? context.parsed.y : context.parsed;
                            return `${datasetLabel}: ${value}`;
                        }
                    }
                }
            },
            scales: isPie ? {} : {
                x: {
                    grid: { display: false },
                    ticks: {
                        font: { size: 10, weight: '500' },
                        color: '#64748b',
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 7
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false },
                    ticks: {
                        font: { size: 10, weight: '500' },
                        color: '#64748b',
                        padding: 6
                    }
                }
            }
        }
    });
}

// Recreate chart whenever data, type or color changes
$: if (previewCanvas && !previewLoading && previewData.length) {
    createPreviewChart();
}

onDestroy(() => {
    if (previewChart) {
        previewChart.destroy();
        previewChart = null;
    }
});

// Test connection to Matomo
async function testConnection(silent = false) {
    if (testing) return;
    testing = true;
    connectionStatus = null;

    try {
        const response = await fetch(`${restUrl}test-connection`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({
                matomo_url: settings.matomo_url,
                token_auth: settings.token_auth,
                site_id: settings.site_id,
                auto_detect_matomo: settings.auto_detect_matomo
            })
        });

        const data = await response.json();

        if (data.success) {
            connectionStatus = 'success';
            connectionMessage = data.message || '';
            if (!silent) {
                showToast(i18n.connectionSuccess || 'Connection successful.', 'success');
            }
        } else {
            connectionStatus = 'error';
            connectionMessage = data.message || data.data?.message || i18n.connectionError || 'Connection failed.';
            if (!silent) {
                showToast(i18n.connectionError || 'Connection failed.', 'error');
            }
        }
    } catch (error) {
        connectionStatus = 'error';
        connectionMessage = error.message;
        if (!silent) {
            showToast(i18n.connectionError || 'Connection failed.', 'error');
        }
    } finally {
        testing = false;
    }
}

async function detectToken() {
    try {
        const response = await fetch(`${restUrl}detect-token`, {
            headers: {
                'X-WP-Nonce': nonce
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.token) {
            settings.token_auth = data.token;
            showToast(i18n.tokenGenerated || 'Token detected automatically.', 'success');
            return { useWpAuth: false, token: data.token };
        } else if (data.use_wp_auth) {
            settings.token_auth = ''; // Clear any existing token
            showToast(i18n.wpAuthUsed || 'WordPress authentication is used.', 'success');
            return { useWpAuth: true };
        }
        
        return { useWpAuth: false };
    } catch (error) {
        console.error('Token detection failed:', error);
        showToast(i18n.detectTokenFailed || 'Token detection failed.', 'error');
        return { useWpAuth: false, error: error.message };
    }
}

function showToast(message, type = 'info') {
    toast = { message, type };
    setTimeout(() => {
        toast = null;
    }, 3000);
}

async function useMatomoWP(silent = false) {
    if (!matomoDetected.installed) {
        if (!silent) {
            showToast(i18n.mfWNotInstalled || 'Matomo for WordPress is not installed.', 'error');
        }
        return;
    }

    if (autoConnecting) return;
    autoConnecting = true;
    connectionStatus = null;
    connectionMessage = '';

    try {
        settings.matomo_url = '';
        settings.auto_detect_matomo = true;

        // Detect token and handle the response
        const result = await detectToken();

        if (result.useWpAuth) {
            // WordPress authentication will be used (no token needed)
            settings.token_auth = '';
            autoConnected = true;
            connectionStatus = 'success';
            connectionMessage = i18n.autoConnected || 'WordPress authentication active';
        } else if (settings.token_auth) {
            // A token was found and set
            autoConnected = true;
            connectionStatus = 'success';
            connectionMessage = i18n.autoConnectedToken || 'Connected to Matomo for WordPress automatically (token detected).';
        } else {
            // No token and no WordPress auth
            autoConnected = false;
            connectionStatus = 'error';
            connectionMessage = i18n.noAuthMethod || 'Could not detect a valid authentication method.';
            if (!silent) {
                showToast(i18n.connectionErrorManual || 'Connection failed. Configure Matomo manually.', 'error');
            }
            autoConnecting = false;
            return;
        }

        // Persist the automatic configuration
        await persistSettings();

        if (!silent) {
            showToast(connectionMessage, 'success');
        }
    } catch (error) {
        autoConnected = false;
        connectionStatus = 'error';
        connectionMessage = error.message || i18n.autoConnectionFailed || 'Automatic connection failed.';
        if (!silent) {
            showToast(i18n.autoConnectionFailed || 'Automatic connection failed.', 'error');
        }
    } finally {
        autoConnecting = false;
    }
}

function toggleAutoDetect() {
    if (settings.auto_detect_matomo) {
        useMatomoWP(true);
    } else {
        autoConnected = false;
        connectionStatus = null;
        connectionMessage = '';
        settings.token_auth = '';
    }
}

onMount(() => {
    fetchSettings();
});
</script>

<div class="lessclicks-settings">
    <!-- Header -->
    <header class="lessclicks-header">
        <div class="lessclicks-header-content">
            <div class="lessclicks-logo">
                <img src="{pluginUrl}assets/images/lessclicks-logo.svg" alt="Lessclicks">
            </div>
            <div>
                <h1>Lessclicks</h1>
                <p>{i18n.tagline || 'Matomo widget dashboard for WordPress'}</p>
            </div>
        </div>
        <div class="lessclicks-header-actions">
            <span class="lessclicks-version">{version ? 'v' + version : ''}</span>
            {#if testing || autoConnecting}
                <span class="lessclicks-status lessclicks-status--checking">
                    <span class="lessclicks-status-dot lessclicks-status-dot--spin"></span>
                    {i18n.checking || 'Checking connection…'}
                </span>
            {:else if connectionStatus === 'success'}
                <span class="lessclicks-status lessclicks-status--connected" title={connectionMessage}>
                    <span class="lessclicks-status-dot"></span>
                    {matomoDetected.installed && settings.auto_detect_matomo ? (i18n.matomoWp || 'Matomo WP') : (i18n.connected || 'Connected')}
                </span>
            {:else if connectionStatus === 'error'}
                <span class="lessclicks-status lessclicks-status--disconnected" title={connectionMessage}>
                    <span class="lessclicks-status-dot"></span>
                    {i18n.notConnected || 'Not connected'}
                </span>
            {:else}
                <span class="lessclicks-status lessclicks-status--pending">
                    <span class="lessclicks-status-dot"></span>
                    {i18n.configurationRequired || 'Configuration required'}
                </span>
            {/if}
        </div>
    </header>

    <!-- Tabs -->
    <div class="lessclicks-tabs" role="tablist" aria-label={i18n.settingsTabs || 'Settings sections'}>
        <button 
            class="lessclicks-tab" 
            class:lessclicks-tab--active={activeTab === 'settings'}
            on:click={() => activeTab = 'settings'}
            role="tab"
            aria-selected={activeTab === 'settings'}
            aria-controls="lessclicks-tab-panel"
            id="lessclicks-tab-settings"
            type="button"
        >
            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>{i18n.settings || 'Settings'}
        </button>
        <button 
            class="lessclicks-tab" 
            class:lessclicks-tab--active={activeTab === 'help'}
            on:click={() => activeTab = 'help'}
            role="tab"
            aria-selected={activeTab === 'help'}
            aria-controls="lessclicks-tab-panel"
            id="lessclicks-tab-help"
            type="button"
        >
            <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>{i18n.help || 'Help'}
        </button>
    </div>

    {#if loading}
        <div class="lessclicks-card" role="status" aria-live="polite" aria-label={i18n.loadingSettings || 'Loading settings'}>
            <div class="lessclicks-card-body">
                <div class="lessclicks-skeleton" style="height: 24px; margin-bottom: 16px;"></div>
                <div class="lessclicks-skeleton" style="height: 120px;"></div>
            </div>
        </div>
    {:else}
    {#key activeTab}
    <div class="lessclicks-tab-panel" id="lessclicks-tab-panel" role="tabpanel" aria-labelledby="lessclicks-tab-{activeTab}" in:fade={{ duration: 200, delay: 50 }}>
        <!-- Settings Tab -->
        {#if activeTab === 'settings'}
            <div class="lessclicks-grid">
                <div class="lessclicks-card lessclicks-card--flex">
                    <div class="lessclicks-card-header">
                        <h2 class="lessclicks-card-title">
                            <span class="lessclicks-card-icon"><span class="dashicons dashicons-admin-customizer" aria-hidden="true"></span></span>
                            {i18n.chartSettings || 'Chart Settings'}
                        </h2>
                    </div>
                    <div class="lessclicks-card-body lessclicks-card-body--grow">
                        {#if !matomoDetected.installed || !settings.auto_detect_matomo}
                            <div class="lessclicks-form-group">
                                <label class="lessclicks-label" for="lessclicks-matomo-url">
                                    {i18n.matomoUrl || 'Matomo URL'}
                                    <span class="lessclicks-label-hint">({i18n.matomoUrlHint || 'Your Matomo installation'})</span>
                                </label>
                                <LessclicksInput
                                    id="lessclicks-matomo-url"
                                    type="url"
                                    bind:value={settings.matomo_url}
                                    placeholder={i18n.matomoUrlPlaceholder || 'https://analytics.example.com'}
                                />
                            </div>
                        {/if}

                        {#if matomoDetected.installed}
                            <div class="lessclicks-form-group">
                                <label class="lessclicks-checkbox">
                                    <input type="checkbox" bind:checked={settings.auto_detect_matomo} on:change={toggleAutoDetect}>
                                    <span>{i18n.useMatomoWp || 'Use Matomo for WordPress'}</span>
                                </label>
                            </div>
                        {/if}

                        <div class="lessclicks-form-row">
                            <div class="lessclicks-form-group lessclicks-form-group--compact">
                                <label class="lessclicks-label" for="lessclicks-site-id">
                                    {i18n.siteId || 'Site ID'}
                                    <span class="lessclicks-label-hint">({i18n.siteIdHint || 'Default: 1'})</span>
                                </label>
                                <LessclicksInput
                                    id="lessclicks-site-id"
                                    type="number"
                                    compact={true}
                                    bind:value={settings.site_id}
                                    min="1"
                                />
                            </div>

                            <div class="lessclicks-form-group lessclicks-form-group--grow">
                                <label class="lessclicks-label" for="lessclicks-auth-token">
                                    {i18n.authToken || 'Auth token'}
                                    <span class="lessclicks-label-hint">
                                        {#if autoConnected && settings.token_auth}
                                            ({i18n.autoDetected || 'detected automatically'})
                                        {:else if autoConnected && !settings.token_auth}
                                            ({i18n.authActive || 'Authentication active'})
                                        {:else}
                                            ({i18n.apiAccessToken || 'API access token'})
                                        {/if}
                                    </span>
                                </label>
                                <div class="lessclicks-input-group">
                                    <input 
                                        type={autoConnected && !settings.token_auth ? 'password' : 'text'}
                                        class="lessclicks-input" 
                                        class:lessclicks-input--readonly={autoConnected || (matomoDetected.installed && settings.token_auth)}
                                        value={autoConnected && !settings.token_auth
                                            ? (showToken ? wpAuthPlaceholder : maskToken(wpAuthPlaceholder))
                                            : (showToken ? settings.token_auth : (settings.token_auth ? maskToken(settings.token_auth) : ''))
                                        }
                                        placeholder={i18n.authTokenPlaceholder || 'Your Matomo API token'}
                                        readonly={autoConnected || (matomoDetected.installed && settings.token_auth)}
                                        on:input={(e) => { if (!autoConnected && !(matomoDetected.installed && settings.token_auth)) settings.token_auth = e.target.value; }}
                                    />
                                    <button 
                                        type="button"
                                        class="lessclicks-btn lessclicks-btn--icon"
                                        on:click={() => showToken = !showToken}
                                        title={showToken ? (i18n.hideToken || 'Hide token') : (i18n.showToken || 'Show token')}
                                    >
                                        <span class="dashicons" class:dashicons-visibility={!showToken} class:dashicons-hidden={showToken}></span>
                                    </button>
                                    {#if matomoDetected.installed && !autoConnected && !settings.token_auth}
                                        <button 
                                            type="button"
                                            class="lessclicks-btn lessclicks-btn--secondary"
                                            on:click={detectToken}
                                            title={i18n.autoDetect || 'Detect token automatically'}
                                        >
                                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                            <span class="screen-reader-text">{i18n.autoDetect || 'Detect token automatically'}</span>
                                        </button>
                                    {/if}
                                </div>
                            </div>
                        </div>

                        {#if connectionMessage}
                            <div class="lessclicks-alert lessclicks-alert--inline" class:lessclicks-alert--success={connectionStatus === 'success'} class:lessclicks-alert--error={connectionStatus === 'error'}>
                                <span class="lessclicks-alert-icon">
                                    <span class="dashicons" class:dashicons-yes={connectionStatus === 'success'} class:dashicons-no={connectionStatus === 'error'} aria-hidden="true"></span>
                                </span>
                                <div class="lessclicks-alert-content">
                                    <p>{connectionMessage}</p>
                                </div>
                            </div>
                        {/if}

                        <div class="lessclicks-form-group">
                            <span class="lessclicks-label" id="lessclicks-chart-type-label">{i18n.chartType || 'Chart-Type'}</span>
                            <div class="lessclicks-chart-types">
                                {#each chartTypes as type}
                                    <label class="lessclicks-chart-type" class:active={settings.chart_type === type.value}>
                                        <input 
                                            type="radio" 
                                            name="chart_type" 
                                            value={type.value}
                                            bind:group={settings.chart_type}
                                        />
                                        <span class="lessclicks-chart-type-icon dashicons {type.iconClass}" aria-hidden="true"></span>
                                        <span class="lessclicks-chart-type-label">{type.label}</span>
                                    </label>
                                {/each}
                            </div>
                        </div>

                        <div class="lessclicks-form-row">
                            <div class="lessclicks-form-group">
                                <label class="lessclicks-label" for="lessclicks-chart-color">{i18n.primaryColor || 'Main color'}</label>
                                <div class="lessclicks-color-picker">
                                    <input id="lessclicks-chart-color" 
                                        type="color" 
                                        class="lessclicks-color-input"
                                        bind:value={settings.chart_color}
                                    />
                                    <span class="lessclicks-color-value">{settings.chart_color}</span>
                                </div>
                            </div>

                            <div class="lessclicks-form-group">
                                <label class="lessclicks-label" for="lessclicks-date-range">{i18n.defaultPeriod || 'Initial Date filter'}</label>
                                <select id="lessclicks-date-range" class="lessclicks-select" bind:value={settings.date_range}>
                                    {#each dateRanges as range}
                                        <option value={range.value}>{range.label}</option>
                                    {/each}
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="lessclicks-card-footer lessclicks-card-footer--sticky">
                        {#if connectionStatus !== null}
                            <button 
                                type="button"
                                class="lessclicks-btn lessclicks-btn--tertiary"
                                on:click={resetConnectionTest}
                                title={i18n.resetConnectionTest || 'Reset connection status'}
                            >
                                <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                                {i18n.reset || 'Reset'}
                            </button>
                        {/if}
                        <button 
                            class="lessclicks-btn lessclicks-btn--primary"
                            on:click={saveSettings}
                            disabled={saving}
                            class:lessclicks-btn--loading={saving}
                            aria-busy={saving}
                            aria-live="polite"
                        >
                            {#if saving}
                                <span class="lessclicks-btn-spinner" aria-hidden="true"></span>
                            {:else}
                                <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                            {/if}
                            {i18n.save || 'Save'}
                        </button>
                    </div>
                </div>

                <!-- Live Preview -->
                <div class="lessclicks-card">
                    <div class="lessclicks-card-header">
                        <h2 class="lessclicks-card-title">
                            <span class="lessclicks-card-icon"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></span>
                            {i18n.livePreview || 'Live preview'}
                        </h2>
                    </div>
                    <div class="lessclicks-card-body">
                        <div class="lessclicks-preview lessclicks-preview--live">
                            <div class="lessclicks-preview-chart lessclicks-preview-chart--canvas" aria-live="polite">
                                {#if previewLoading}
                                    <div class="lessclicks-preview-status" role="status">
                                        <span class="lessclicks-preview-spinner" aria-hidden="true"></span>
                                        {i18n.loadingPreview || 'Loading preview…'}
                                    </div>
                                {:else if previewError || !previewData.length}
                                    <div class="lessclicks-preview-status">{i18n.noPreviewData || 'No preview data'}</div>
                                {:else}
                                    <canvas bind:this={previewCanvas} class="lessclicks-preview-canvas"></canvas>
                                {/if}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        {/if}

        <!-- Help Tab -->
        {#if activeTab === 'help'}
            <div class="lessclicks-grid">
                <div class="lessclicks-card">
                    <div class="lessclicks-card-header">
                        <h2 class="lessclicks-card-title">
                            <span class="lessclicks-card-icon"><span class="dashicons dashicons-book-alt" aria-hidden="true"></span></span>
                            {i18n.quickstart || 'Quick start'}
                        </h2>
                    </div>
                    <div class="lessclicks-card-body">
                        <div class="lessclicks-help-steps">
                            {#if matomoDetected.installed}
                                <!-- Simplified guide for Matomo for WordPress -->
                                <div class="lessclicks-help-step">
                                    <span class="lessclicks-help-step-number">1</span>
                                    <div class="lessclicks-help-step-content">
                                        <strong>{i18n.connectAutomatically || 'Connect automatically'}</strong>
                                        <p>{i18n.connectAutomaticallyHelp || 'Matomo for WordPress is detected. Lessclicks connects automatically and adopts the settings.'}</p>
                                    </div>
                                </div>
                                <div class="lessclicks-help-step">
                                    <span class="lessclicks-help-step-number">2</span>
                                    <div class="lessclicks-help-step-content">
                                        <strong>{i18n.useDashboardWidget || 'Use the dashboard widget'}</strong>
                                        <p>{i18n.useDashboardWidgetHelp || 'After connecting, the analytics widget appears on the WordPress dashboard. Adjust the period, chart type and color in the widget.'}</p>
                                    </div>
                                </div>
                                <div class="lessclicks-help-step">
                                    <span class="lessclicks-help-step-number">3</span>
                                    <div class="lessclicks-help-step-content">
                                        <strong>{i18n.exportReports || 'Export reports'}</strong>
                                        <p>{i18n.exportReportsHelp || 'Use the Report button in the widget to export your statistics as a PDF or PNG.'}</p>
                                    </div>
                                </div>
                            {:else}
                                <!-- Guide for external Matomo installation -->
                                <div class="lessclicks-help-step">
                                    <span class="lessclicks-help-step-number">1</span>
                                    <div class="lessclicks-help-step-content">
                                        <strong>{i18n.installMfW || 'Install Matomo for WordPress'}</strong>
                                        <p>{i18n.installMfWHelp || 'For the best integration, install the free Matomo Analytics plugin from the WordPress plugin directory.'}</p>
                                    </div>
                                </div>
                                <div class="lessclicks-help-step">
                                    <span class="lessclicks-help-step-number">2</span>
                                    <div class="lessclicks-help-step-content">
                                        <strong>{i18n.externalMfW || 'Alternative: external Matomo'}</strong>
                                        <p>{i18n.externalMfWHelp || 'If you host Matomo externally, enter the Matomo URL, Site ID and API token under Chart Settings.'}</p>
                                    </div>
                                </div>
                                <div class="lessclicks-help-step">
                                    <span class="lessclicks-help-step-number">3</span>
                                    <div class="lessclicks-help-step-content">
                                        <strong>{i18n.testConnection || 'Check the connection'}</strong>
                                        <p>{i18n.testConnectionHelp || 'Save the settings and check the connection status at the top of this page.'}</p>
                                    </div>
                                </div>
                            {/if}
                        </div>

                        <!-- Features overview -->
                        <div class="lessclicks-features-section">
                            <h3 class="lessclicks-features-title">{i18n.widgetFeatures || 'Widget features'}</h3>
                            <div class="lessclicks-feature-cards">
                                <div class="lessclicks-feature-card">
                                    <div class="lessclicks-feature-card-icon">
                                        <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                                    </div>
                                    <div class="lessclicks-feature-card-content">
                                        <h4>{i18n.statsCards || 'Stats cards'}</h4>
                                        <p>{i18n.statsCardsDesc || 'Visitors, page views, bounce rate and session duration at a glance.'}</p>
                                    </div>
                                </div>
                                <div class="lessclicks-feature-card">
                                    <div class="lessclicks-feature-card-icon">
                                        <span class="dashicons dashicons-chart-line"></span>
                                    </div>
                                    <div class="lessclicks-feature-card-content">
                                        <h4>{i18n.interactiveCharts || 'Interactive charts'}</h4>
                                        <p>{i18n.interactiveChartsDesc || 'Line, bar or pie chart.'}</p>
                                    </div>
                                </div>
                                <div class="lessclicks-feature-card">
                                    <div class="lessclicks-feature-card-icon">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                    </div>
                                    <div class="lessclicks-feature-card-content">
                                        <h4>{i18n.flexiblePeriod || 'Flexible period'}</h4>
                                        <p>{i18n.flexiblePeriodDesc || 'Pick any period with the date picker in the widget.'}</p>
                                    </div>
                                </div>
                                <div class="lessclicks-feature-card">
                                    <div class="lessclicks-feature-card-icon">
                                        <span class="dashicons dashicons-download"></span>
                                    </div>
                                    <div class="lessclicks-feature-card-content">
                                        <h4>{i18n.exportReady || 'PDF & PNG export'}</h4>
                                        <p>{i18n.exportReadyDesc || 'Download statistics as a PDF report or PNG image.'}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="lessclicks-card">
                    <div class="lessclicks-card-header">
                        <h2 class="lessclicks-card-title">
                            <span class="lessclicks-card-icon"><span class="dashicons dashicons-heart" aria-hidden="true"></span></span>
                            {i18n.supportTitle || 'Support Matt Interfaces'}
                        </h2>
                    </div>
                    <div class="lessclicks-card-body">
                        <p class="lessclicks-desc">{i18n.supportDesc || 'This plugin is free. A donation or a review helps with further development.'}</p>
                        <div class="lessclicks-support">
                            <a href="https://matt-interfaces.ch/lessclicks" target="_blank" rel="noopener noreferrer" class="lessclicks-support-link" aria-label={i18n.pluginWebsiteAria || 'Visit the Lessclicks Analytics for Matomo plugin page'}>
                                <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                                <span>{i18n.pluginWebsite || 'Plugin website'}</span>
                            </a>
                            <a href="https://github.com/Matt-Interfaces/lessclicks" target="_blank" rel="noopener noreferrer" class="lessclicks-support-link" aria-label={i18n.githubRepositoryAria || 'Open the Lessclicks GitHub repository'}>
                                <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                                <span>{i18n.githubRepository || 'GitHub repository'}</span>
                            </a>
                            <a href="https://developer.matomo.org/api-reference/reporting-api" target="_blank" rel="noopener noreferrer" class="lessclicks-support-link" >
                                <span class="dashicons dashicons-book-alt" aria-hidden="true"></span>
                                <span>{i18n.matomoApiDocs || 'Matomo API docs'}</span>
                            </a>
                            <a href="https://matt-interfaces.ch/zahlen" target="_blank" rel="noopener noreferrer" class="lessclicks-support-link" >
                                <span class="dashicons dashicons-heart" aria-hidden="true"></span>
                                <span>{i18n.supportDeveloper || 'Support the developer'}</span>
                            </a>
                            <a href="https://matt-interfaces.ch" target="_blank" rel="noopener noreferrer" class="lessclicks-support-link">
                                <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                                <span>matt-interfaces.ch</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        {/if}
    </div>
    {/key}
    {/if}

    <!-- Toast Notification -->
    {#if toast}
        <div class="lessclicks-toast" class:lessclicks-toast--success={toast.type === 'success'} class:lessclicks-toast--error={toast.type === 'error'}>
            {toast.message}
    </div>
    {/if}
</div>

<style>
    .lessclicks-chart-type input {
        display: none;
    }

    .lessclicks-preview-chart--canvas {
        position: relative;
        width: 100%;
        height: 240px;
    }

    .lessclicks-preview-canvas {
        position: absolute;
        top: 0;
        left: 0;
        width: 100% !important;
        height: 100% !important;
    }
</style>
