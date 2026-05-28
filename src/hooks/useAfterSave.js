import {useSelect} from '@wordpress/data';
import {useEffect, useRef, useState} from '@wordpress/element';

/**
 * Returns `true` if the post is done saving, `false` otherwise.
 *
 * @returns {Boolean}
 */
export const useAfterSave = () => {
    const [ isPostSaved, setIsPostSaved ] = useState( false );
    const isPostSavingInProgress = useRef( false );
    const { isSavingPost, isAutosavingPost } = useSelect( ( __select ) => {
        return {
            isSavingPost: __select( 'core/editor' ).isSavingPost(),
            isAutosavingPost: __select( 'core/editor' ).isAutosavingPost(),
        }
    } );

    useEffect( () => {
        // console.log( 'isSavingPost:', isSavingPost );
        // console.log( 'isAutosavingPost:', isAutosavingPost );
        // console.log( 'isPostSavingInProgress', isPostSavingInProgress.current );
        // Track only deliberate saves, not background auto-saves.
        if ( isSavingPost && ! isAutosavingPost && ! isPostSavingInProgress.current ) {
            setIsPostSaved( false );
            isPostSavingInProgress.current = true;
        }
        if ( ! isSavingPost && isPostSavingInProgress.current ) {
            // Code to run after post is done saving.
            setIsPostSaved( true );
            isPostSavingInProgress.current = false;
        }
    }, [ isSavingPost, isAutosavingPost ] );

    return isPostSaved;
};