import { useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { noCachePath } from '../utils/api';

const SAVE_LOCK_TIMEOUT_MS = 10_000;

export function usePostLinkApi() {
    const saveLockTimer = useRef( null );

    const getCurrentPostId = useCallback( () => {
        return wp.data.select( 'core/editor' ).getEditedPostAttribute( 'id' );
    }, [] );

    const lockPostSaving = useCallback( () => {
        wp.data.dispatch( 'core/editor' ).lockPostSaving( 'bolt_sync' );

        // Safety valve: auto-unlock after 10 s so the editor can't get stuck.
        saveLockTimer.current = setTimeout( () => {
            wp.data.dispatch( 'core/editor' ).unlockPostSaving( 'bolt_sync' );
        }, SAVE_LOCK_TIMEOUT_MS );
    }, [] );

    const unlockPostSaving = useCallback( () => {
        clearTimeout( saveLockTimer.current );
        wp.data.dispatch( 'core/editor' ).unlockPostSaving( 'bolt_sync' );
    }, [] );

    const getLinkId = useCallback( async () => {
        const postId = getCurrentPostId();

        const linkId = await apiFetch( {
            path: noCachePath( `/bolt-sync/v1/get-link-id/${ postId }` ),
            method: 'GET',
        } );

        return linkId !== 0 ? linkId : null;
    }, [ getCurrentPostId ] );

    const fetchData = useCallback( async () => {
        const postId = getCurrentPostId();
        lockPostSaving();

        try {
            return await apiFetch( {
                path: noCachePath( `/bolt-sync/v1/bolt-sync-manager/${ postId }` ),
                method: 'GET',
            } );
        } finally {
            unlockPostSaving();
        }
    }, [ getCurrentPostId, lockPostSaving, unlockPostSaving ] );

    const insertLink = useCallback( async ( sites ) => {
        const postId = getCurrentPostId();

        const linkId = await apiFetch( {
            path: '/bolt-sync/v1/link',
            method: 'POST',
            data: { sites, postId },
        } );

        return linkId;
    }, [ getCurrentPostId ] );

    const updateLink = useCallback( async ( linkId, sites ) => {
        const postId = getCurrentPostId();

        return apiFetch( {
            path: `/bolt-sync/v1/link/${ linkId }`,
            method: 'POST',
            data: { sites, postId },
        } );
    }, [ getCurrentPostId ] );

    const deleteLink = useCallback( async ( linkId ) => {
        const postId = getCurrentPostId();

        return apiFetch( {
            path: `/bolt-sync/v1/link/${ linkId }`,
            method: 'DELETE',
            data: { linkId, postId },
        } );
    }, [ getCurrentPostId ] );

    const leaveLink = useCallback( async ( linkId ) => {
        const postId = getCurrentPostId();

        return apiFetch( {
            path: `/bolt-sync/v1/link/${ linkId }/leave`,
            method: 'DELETE',
            data: { post_id: postId },
        } );
    }, [ getCurrentPostId ] );

    const triggerSync = useCallback( async () => {
        const postId = getCurrentPostId();

        return apiFetch( {
            path: '/bolt-sync/v1/sync',
            method: 'POST',
            data: { post_id: postId },
        } );
    }, [ getCurrentPostId ] );

    const joinLink = useCallback( async ( linkId ) => {
        const postId = getCurrentPostId();

        return apiFetch( {
            path: `/bolt-sync/v1/link/${ linkId }/join`,
            method: 'POST',
            data: { post_id: postId },
        } );
    }, [ getCurrentPostId ] );

    /**
     * Polls GET /bolt-sync/v1/sync-status/{postId} every 5 s until the job is
     * complete, failed, or the timeout is reached. Calls onUpdate(status) on
     * each tick and onDone(status) when polling stops.
     */
    const pollSyncStatus = useCallback( ( { onUpdate, onDone, timeoutMs = 120_000 } = {} ) => {
        const postId  = getCurrentPostId();
        const started = Date.now();
        let   timer   = null;

        const TERMINAL_STATES = [ 'complete', 'failed', 'idle', 'unavailable' ];

        const tick = async () => {
            if ( Date.now() - started >= timeoutMs ) {
                onDone?.( 'timeout' );
                return;
            }

            let status = 'unknown';
            let res    = null;

            try {
                res    = await apiFetch( {
                    path: noCachePath( `/bolt-sync/v1/sync-status/${ postId }` ),
                    method: 'GET',
                } );
                status = res?.status ?? 'unknown';
            } catch {
                status = 'error';
            }

            onUpdate?.( status, res );

            if ( TERMINAL_STATES.includes( status ) ) {
                onDone?.( status );
                return;
            }

            timer = setTimeout( tick, 5_000 );
        };

        tick();

        return () => clearTimeout( timer );
    }, [ getCurrentPostId ] );

    return {
        getLinkId,
        fetchData,
        insertLink,
        updateLink,
        deleteLink,
        leaveLink,
        joinLink,
        triggerSync,
        pollSyncStatus,
    };
}
