/**
 * Demande Status Sync System
 * Handles real-time synchronization of demande status changes across tabs and pages
 */

(function(window) {
    'use strict';

    const DemandeSyncManager = {
        channel: null,
        listeners: [],

        /**
         * Initialize the sync manager
         */
        init: function() {
            try {
                // Try to use BroadcastChannel for same-origin communication
                if (window.BroadcastChannel) {
                    this.channel = new BroadcastChannel('demande-status-sync');
                    this.channel.onmessage = (event) => {
                        console.log('[DemandeSyncManager] Received broadcast:', event.data);
                        this.notifyListeners(event.data);
                    };
                }
            } catch (e) {
                console.warn('[DemandeSyncManager] BroadcastChannel not available:', e);
            }

            // Also listen for storage events (for cross-tab communication)
            window.addEventListener('storage', (event) => {
                if (event.key === 'demande-status-update') {
                    try {
                        const data = JSON.parse(event.newValue);
                        console.log('[DemandeSyncManager] Received storage event:', data);
                        this.notifyListeners(data);
                    } catch (e) {
                        console.error('[DemandeSyncManager] Error parsing storage event:', e);
                    }
                }
            });
        },

        /**
         * Register a listener for status updates
         * @param {Function} callback - Function to call when status updates
         */
        onStatusUpdate: function(callback) {
            if (typeof callback === 'function') {
                this.listeners.push(callback);
            }
        },

        /**
         * Notify all registered listeners of a status update
         * @param {Object} data - The update data
         */
        notifyListeners: function(data) {
            this.listeners.forEach(callback => {
                try {
                    callback(data);
                } catch (e) {
                    console.error('[DemandeSyncManager] Error in listener callback:', e);
                }
            });
        },

        /**
         * Broadcast a status update to other tabs
         * @param {Object} updateData - The update data to broadcast
         */
        broadcast: function(updateData) {
            const data = {
                timestamp: Date.now(),
                ...updateData
            };

            // Broadcast via BroadcastChannel
            if (this.channel) {
                try {
                    this.channel.postMessage(data);
                } catch (e) {
                    console.warn('[DemandeSyncManager] Error broadcasting:', e);
                }
            }

            // Also update localStorage for cross-tab communication
            try {
                localStorage.setItem('demande-status-update', JSON.stringify(data));
                // Trigger storage event for same-tab listeners
                setTimeout(() => {
                    this.notifyListeners(data);
                }, 100);
            } catch (e) {
                console.warn('[DemandeSyncManager] Error updating localStorage:', e);
            }
        },

        /**
         * Clean up resources
         */
        destroy: function() {
            if (this.channel) {
                this.channel.close();
                this.channel = null;
            }
            this.listeners = [];
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            DemandeSyncManager.init();
        });
    } else {
        DemandeSyncManager.init();
    }

    // Expose to window
    window.DemandeSyncManager = DemandeSyncManager;

})(window);
