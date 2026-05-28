import { useCallback } from '@wordpress/element';

/**
 * Encapsulates the explicit save, delete, and refresh actions for the bolt-sync-manager.
 *
 * Reads current state via a ref so callbacks stay stable across renders.
 *
 * @param {{ stateRef: React.MutableRefObject, dispatch: Function, api: object }} param0
 * @returns {{ handleSave: Function, handleDelete: Function, handleRefresh: Function }}
 */
export function useLinkActions( { stateRef, dispatch, api } ) {
	const { fetchData, getLinkId, insertLink, updateLink, deleteLink, leaveLink, joinLink } = api;

	const handleSave = useCallback( async () => {
		const { sites, linkId, suggestedGroupId, isSaving } = stateRef.current;
		if ( ! sites || isSaving ) return;

		const allInactive     = sites.every( ( s ) => ! s.link_info?.active );
		const hasActive       = sites.some( ( s ) => s.link_info?.active );
		const currentSiteLeft = linkId && sites.some( ( s ) => s.is_current_site && ! s.isLink );

		// A join is triggered when there is no existing link but a peer group is available.
		const anyActiveWithMatchingGroup = sites.some( ( s ) => s.link_info?.active && s.matching_link_id );
		const joinGroupId = ! linkId && ( anyActiveWithMatchingGroup || suggestedGroupId )
			? ( sites.find( ( s ) => s.matching_link_id )?.matching_link_id ?? suggestedGroupId )
			: null;

		dispatch( { type: 'SAVE_START' } );

		try {
			if ( linkId ) {
				if ( currentSiteLeft ) {
					await leaveLink( linkId );
					wp.data.dispatch( 'core/editor' ).editPost( { meta: { bolt_sync_link_id: 0 } } );
					const data = await fetchData();
					dispatch( { type: 'SAVE_SUCCESS', linkId: null, sites: data.sites, suggestedGroupId: data.suggested_group_id } );

				} else if ( allInactive ) {
					await deleteLink( linkId );
					wp.data.dispatch( 'core/editor' ).editPost( { meta: { bolt_sync_link_id: 0 } } );
					const data = await fetchData();
					dispatch( { type: 'SAVE_SUCCESS', linkId: null, sites: data.sites, suggestedGroupId: data.suggested_group_id } );

				} else {
					await updateLink( linkId, sites );
					wp.data.dispatch( 'core/editor' ).editPost( { meta: { bolt_sync_link_id: linkId } } );
					dispatch( { type: 'SAVE_SUCCESS', linkId } );
				}

			} else if ( joinGroupId ) {
				await joinLink( joinGroupId );
				const [ freshLinkId, data ] = await Promise.all( [ getLinkId(), fetchData() ] );
				wp.data.dispatch( 'core/editor' ).editPost( { meta: { bolt_sync_link_id: freshLinkId ?? 0 } } );
				dispatch( { type: 'SAVE_SUCCESS', linkId: freshLinkId, sites: data.sites, suggestedGroupId: data.suggested_group_id } );

			} else if ( hasActive ) {
				const newLinkId = await insertLink( sites );
				wp.data.dispatch( 'core/editor' ).editPost( { meta: { bolt_sync_link_id: newLinkId } } );
				dispatch( { type: 'SAVE_SUCCESS', linkId: newLinkId } );

			} else {
				// Nothing to persist — just exit edit mode.
				dispatch( { type: 'SAVE_SUCCESS', linkId } );
			}
		} catch ( err ) {
			console.error( 'BoltSync save error:', err );
			dispatch( { type: 'SAVE_ERROR', error: 'Failed to save link changes.' } );
		}
		// stateRef is a stable ref — intentionally excluded from deps.
	}, [ dispatch, fetchData, getLinkId, insertLink, updateLink, deleteLink, leaveLink, joinLink ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleDelete = useCallback( async () => {
		const { linkId } = stateRef.current;
		if ( ! linkId ) return;

		dispatch( { type: 'DELETE_START' } );

		try {
			await deleteLink( linkId );
			wp.data.dispatch( 'core/editor' ).editPost( { meta: { bolt_sync_link_id: 0 } } );
			const data = await fetchData();
			dispatch( { type: 'DELETE_SUCCESS', sites: data.sites, suggestedGroupId: data.suggested_group_id } );
		} catch ( err ) {
			console.error( 'BoltSync delete error:', err );
			dispatch( { type: 'DELETE_ERROR', error: 'Failed to delete link.' } );
		}
		// stateRef is a stable ref — intentionally excluded from deps.
	}, [ dispatch, deleteLink, fetchData ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleLeave = useCallback( async () => {
		const { linkId } = stateRef.current;
		if ( ! linkId ) return;

		dispatch( { type: 'DELETE_START' } );

		try {
			await leaveLink( linkId );
			wp.data.dispatch( 'core/editor' ).editPost( { meta: { bolt_sync_link_id: 0 } } );
			const data = await fetchData();
			dispatch( { type: 'DELETE_SUCCESS', sites: data.sites, suggestedGroupId: data.suggested_group_id } );
		} catch ( err ) {
			console.error( 'BoltSync leave error:', err );
			dispatch( { type: 'DELETE_ERROR', error: 'Failed to leave link group.' } );
		}
		// stateRef is a stable ref — intentionally excluded from deps.
	}, [ dispatch, leaveLink, fetchData ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleRefresh = useCallback( async () => {
		dispatch( { type: 'REFRESH_START' } );

		try {
			const [ freshLinkId, data ] = await Promise.all( [ getLinkId(), fetchData() ] );
			dispatch( { type: 'REFRESH_SUCCESS', linkId: freshLinkId, sites: data.sites, suggestedGroupId: data.suggested_group_id, validationStatus: data.validation_status ?? null } );
		} catch ( err ) {
			console.error( 'BoltSync refresh error:', err );
			dispatch( { type: 'REFRESH_ERROR', error: 'Failed to refresh link data.' } );
		}
	}, [ dispatch, getLinkId, fetchData ] );

	return { handleSave, handleDelete, handleLeave, handleRefresh };
}
