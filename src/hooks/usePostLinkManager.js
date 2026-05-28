import { useReducer, useEffect, useRef, useCallback } from '@wordpress/element';
import { usePostLinkApi } from './usePostLinkApi';
import { useSyncStatus } from './useSyncStatus';
import { useLinkActions } from './useLinkActions';

// ─────────────────────────────────────────────────────────────────────────────
// State shape & reducer
// ─────────────────────────────────────────────────────────────────────────────

const initialState = {
	sites:            null,
	suggestedGroupId: null,
	linkId:           null,
	isSaving:         false,
	isDeleting:       false,
	isRefreshing:     false,
	isInitialized:    false,
	sitesHistory:     null,
	validationStatus: null,
	error:            null,
};

function reducer( state, action ) {
	switch ( action.type ) {
		case 'INIT_SUCCESS':
			return {
				...state,
				sites:            action.sites,
				suggestedGroupId: action.suggestedGroupId,
				linkId:           action.linkId,
				sitesHistory:     action.sites,
				isInitialized:    true,
				validationStatus: action.validationStatus ?? null,
				error:            null,
			};

		case 'INIT_ERROR':
			return { ...state, error: action.error, isInitialized: true };

		case 'SET_SITES':
			return { ...state, sites: typeof action.sites === 'function' ? action.sites( state.sites ) : action.sites };

		case 'SAVE_START':
			return { ...state, isSaving: true, validationStatus: null, error: null };

		case 'SAVE_SUCCESS': {
			const newSites = action.sites ?? state.sites;
			return {
				...state,
				isSaving:         false,
				linkId:           action.linkId !== undefined ? action.linkId : state.linkId,
				sites:            newSites,
				suggestedGroupId: action.suggestedGroupId !== undefined ? action.suggestedGroupId : state.suggestedGroupId,
				sitesHistory:     newSites,
			};
		}

		case 'SAVE_ERROR':
			return { ...state, isSaving: false, error: action.error };

		case 'DELETE_START':
			return { ...state, isSaving: true, isDeleting: true, error: null };

		case 'DELETE_SUCCESS':
			return {
				...state,
				isSaving:         false,
				isDeleting:       false,
				linkId:           null,
				sites:            action.sites,
				suggestedGroupId: action.suggestedGroupId ?? null,
				sitesHistory:     action.sites,
			};

		case 'DELETE_ERROR':
			return { ...state, isSaving: false, isDeleting: false, error: action.error };

		case 'REFRESH_START':
			return { ...state, isRefreshing: true };

		case 'REFRESH_SUCCESS':
			return {
				...state,
				isRefreshing:     false,
				sites:            action.sites,
				suggestedGroupId: action.suggestedGroupId ?? null,
				linkId:           action.linkId,
				sitesHistory:     action.sites,
				validationStatus: action.validationStatus ?? null,
				error:            null,
			};

		case 'REFRESH_ERROR':
			return { ...state, isRefreshing: false, error: action.error };

		default:
			return state;
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function computeHasChanges( state ) {
	const { sites, sitesHistory } = state;
	if ( ! sitesHistory || ! sites ) return false;
	const comparable = ( list ) => list.map( ( item ) => {
		const { post: _post, ...linkInfo } = item.link_info || {};
		return linkInfo;
	} );
	return JSON.stringify( comparable( sitesHistory ) ) !== JSON.stringify( comparable( sites ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Hook
// ─────────────────────────────────────────────────────────────────────────────

export function usePostLinkManager() {
	const [ state, dispatch ] = useReducer( reducer, initialState );

	// Stable ref so action callbacks always read the latest state without
	// needing to be re-created on every render.
	const stateRef = useRef( state );
	useEffect( () => { stateRef.current = state; } );

	const api = usePostLinkApi();

	// Destructure stable callbacks so hooks that receive them don't recreate
	// on every render (api is a plain object literal, never the same reference).
	const { getLinkId, fetchData, insertLink, updateLink, deleteLink, leaveLink, joinLink, triggerSync, pollSyncStatus } = api;

	const { syncStatus, syncLabel, startPolling } = useSyncStatus( { pollSyncStatus } );

	const { handleSave, handleDelete, handleLeave, handleRefresh } = useLinkActions( {
		stateRef,
		dispatch,
		api: { getLinkId, fetchData, insertLink, updateLink, deleteLink, leaveLink, joinLink },
	} );

	// ── Initialization ────────────────────────────────────────────────────────

	useEffect( () => {
		const initialize = async () => {
			try {
				let resolvedLinkId;

				if ( 'linkId' in window.boltSync ) {
					resolvedLinkId = window.boltSync.linkId || null;
					delete window.boltSync.linkId;
				} else {
					resolvedLinkId = await api.getLinkId();
				}

				let data;

				if ( window.boltSync?.boltSyncManager ) {
					const injected = window.boltSync.boltSyncManager;
					// Handle legacy flat-array shape from older cached pages.
					data = Array.isArray( injected )
						? { sites: injected, suggested_group_id: null }
						: injected;
					delete window.boltSync.boltSyncManager;
				} else {
					data = await api.fetchData();
				}

				dispatch( {
					type:             'INIT_SUCCESS',
					sites:            data.sites,
					suggestedGroupId: data.suggested_group_id ?? null,
					linkId:           resolvedLinkId,
					validationStatus: data.validation_status ?? null,
				} );
			} catch ( err ) {
				console.error( 'BoltSync init error:', err );
				dispatch( { type: 'INIT_ERROR', error: 'Failed to load link data. Please refresh.' } );
			}
		};

		initialize();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Post-save sync polling ────────────────────────────────────────────────

	const handleAfterSave = useCallback( async () => {
		const { linkId, sites } = stateRef.current;
		const hadChanges          = computeHasChanges( stateRef.current );
		const hasActiveRemote     = !! sites?.some( ( s ) => ! s.is_current_site && s.isLink );

		// Nothing to do — solo post with no links and no pending changes to create one.
		if ( ! linkId && ! hasActiveRemote ) return;

		if ( hadChanges ) {
			await handleSave();
			// Only trigger sync if remote sites are actually being linked.
			if ( hasActiveRemote ) {
				await triggerSync().catch( () => {} );
			}
		}

		// Only poll when there is (or will be) a real sync job to watch.
		// If changes were made: poll only when remote sites were involved.
		// If no changes: poll because WP's save_post hook may have queued a job.
		const shouldPoll = hadChanges ? hasActiveRemote : !! linkId;
		if ( shouldPoll ) {
			startPolling( {
				onDone: () => {
					const s = stateRef.current;
					if ( ! s.isSaving && ! computeHasChanges( s ) ) {
						handleRefresh();
					}
				},
			} );
		}
		// stateRef is a stable ref — intentionally excluded from deps.
	}, [ startPolling, handleRefresh, handleSave, triggerSync ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Stable setters ────────────────────────────────────────────────────────

	const setSites = useCallback( ( sites ) => dispatch( { type: 'SET_SITES', sites } ), [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Unsaved-changes detection ─────────────────────────────────────────────

	const hasChanges = computeHasChanges( state );

	return {
		...state,
		syncStatus,
		syncLabel,
		setSites,
		hasChanges,
		handleSave,
		deleteLinkHandler: handleDelete,
		leaveLinkHandler:  handleLeave,
		handleAfterSave,
	};
}
