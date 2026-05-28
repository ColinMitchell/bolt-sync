import { useState, useRef, useCallback, useMemo, useEffect } from '@wordpress/element';

const SYNC_STATUS_LABELS = {
	queued:      'Sync queued…',
	running:     'Syncing…',
	complete:    'Sync complete',
	idle:        'Sync complete',
	unavailable: 'Synced',
	failed:      'Sync failed',
	timeout:     'Sync timed out',
	error:       'Sync status unknown',
};

const FADE_STATUSES = new Set( [ 'complete', 'idle', 'unavailable' ] );

/**
 * Manages sync-status polling and ETA countdown for the bolt-sync-manager panel.
 *
 * @param {{ pollSyncStatus: Function }} param0
 * @returns {{ syncStatus: string|null, etaSeconds: number|null, syncLabel: string|null, startPolling: Function }}
 */
export function useSyncStatus( { pollSyncStatus } ) {
	const [ syncStatus, setSyncStatus ] = useState( null );
	const [ etaSeconds, setEtaSeconds ] = useState( null );

	const stopPollingRef     = useRef( null );
	const syncStatusTimerRef = useRef( null );
	const etaCountdownRef    = useRef( null );

	const clearEtaCountdown = useCallback( () => {
		clearInterval( etaCountdownRef.current );
		etaCountdownRef.current = null;
	}, [] );

	const clearSyncStatusAfterDelay = useCallback( ( delayMs = 5000 ) => {
		clearTimeout( syncStatusTimerRef.current );
		syncStatusTimerRef.current = setTimeout( () => setSyncStatus( null ), delayMs );
	}, [] );

	/**
	 * Starts a new poll cycle. Cancels any in-flight poll first.
	 *
	 * @param {{ onDone?: Function }} [options]
	 */
	const startPolling = useCallback( ( { onDone } = {} ) => {
		setSyncStatus( 'queued' );
		stopPollingRef.current?.();

		const stop = pollSyncStatus( {
			onUpdate: ( status, data ) => {
				setSyncStatus( status );

				const serverEta = data?.eta_seconds ?? null;
				if ( status === 'queued' && serverEta !== null && serverEta > 0 ) {
					setEtaSeconds( serverEta );
					clearEtaCountdown();
					etaCountdownRef.current = setInterval( () => {
						setEtaSeconds( ( prev ) => {
							if ( prev <= 1 ) {
								clearEtaCountdown();
								return 0;
							}
							return prev - 1;
						} );
					}, 1000 );
				} else {
					clearEtaCountdown();
					setEtaSeconds( null );
				}
			},
			onDone: ( status ) => {
				clearEtaCountdown();
				setEtaSeconds( null );
				setSyncStatus( status );

				if ( FADE_STATUSES.has( status ) ) {
					clearSyncStatusAfterDelay();
				}

				onDone?.( status );
			},
		} );

		stopPollingRef.current = stop;
	}, [ pollSyncStatus, clearEtaCountdown, clearSyncStatusAfterDelay ] );

	useEffect( () => () => {
		stopPollingRef.current?.();
		clearTimeout( syncStatusTimerRef.current );
		clearEtaCountdown();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const syncLabel = useMemo( () => {
		if ( ! syncStatus ) return null;
		const base = SYNC_STATUS_LABELS[ syncStatus ] ?? null;
		if ( ! base ) return null;
		if ( syncStatus === 'queued' && etaSeconds > 0 && etaSeconds < 20 ) {
			return `${ base } ~${ etaSeconds }s`;
		}
		return base;
	}, [ syncStatus, etaSeconds ] );

	return { syncStatus, etaSeconds, syncLabel, startPolling };
}
