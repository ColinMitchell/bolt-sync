import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Flex, FlexBlock, FlexItem, __experimentalText as Text } from '@wordpress/components';

const SYNC_PENDING_STATUSES = new Set( [ 'queued', 'running' ] );
import SitesSelector from './SitesSelector';
import { usePostLinkManager } from '../hooks/usePostLinkManager';
import { useAfterSave } from '../hooks/useAfterSave';

const SYNC_ERROR_STATUSES = new Set( [ 'failed', 'timeout', 'error' ] );

export default function BoltSyncManager() {
	const {
		sites,
		setSites,
		suggestedGroupId,
		linkId,
		sitesHistory,
		isSaving,
		isDeleting,
		isRefreshing,
		syncStatus,
		syncLabel,
		validationStatus,
		error,
		hasChanges,
		handleSave,
		deleteLinkHandler,
		leaveLinkHandler,
		handleAfterSave,
	} = usePostLinkManager();

	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ confirmLeave, setConfirmLeave ]   = useState( false );

	const isAfterSave = useAfterSave();

	useEffect( () => {
		if ( isAfterSave ) {
			handleAfterSave();
		}
	}, [ isAfterSave, handleAfterSave ] );

	// Reset confirmation state when linkId changes (e.g. after leave/delete).
	useEffect( () => {
		setConfirmDelete( false );
		setConfirmLeave( false );
	}, [ linkId ] );

	if ( isRefreshing ) {
		return (
			<Flex>
				<FlexItem><Spinner /></FlexItem>
				<FlexBlock><Text>Refreshing link data…</Text></FlexBlock>
			</Flex>
		);
	}

	if ( ! sites ) {
		return (
			<Flex>
				<FlexItem><Spinner /></FlexItem>
				<FlexBlock><Text>Loading post link data…</Text></FlexBlock>
			</Flex>
		);
	}

	const isAdmin  = window.boltSync?.userRole === 'administrator';
	const showSave = hasChanges || ( ! linkId && !! suggestedGroupId );
	const isError  = SYNC_ERROR_STATUSES.has( syncStatus );

	return (
		<div className="bolt-sync-manager">
			{ error && (
				<p style={ { margin: '0 0 8px', fontSize: '12px', color: '#cc1818' } }>
					{ error }
				</p>
			) }

			<SitesSelector
				sites={ sites }
				setSites={ setSites }
				isSaving={ isSaving }
				linkId={ linkId }
				sitesHistory={ sitesHistory }
			/>

			{ showSave && (
				<div style={ { marginTop: '12px' } }>
					<Button
						variant="primary"
						onClick={ handleSave }
						isBusy={ isSaving }
						disabled={ isSaving }
					>
						{ isSaving ? 'Saving…' : 'Save link' }
					</Button>
				</div>
			) }

			{ syncLabel && (
				<p style={ {
					display:     'flex',
					alignItems:  'center',
					gap:         '6px',
					margin:      '8px 0 0',
					paddingLeft: '10px',
					fontSize:    '12px',
					color:       isError ? '#cc1818' : '#757575',
				} }>
					{ SYNC_PENDING_STATUSES.has( syncStatus ) && (
						<Spinner style={ { margin: 0, width: '16px', height: '16px' } } />
					) }
					{ syncLabel }
				</p>
			) }

			{ linkId && (
				<div style={ { marginTop: '12px' } }>
					{ ( confirmLeave || confirmDelete ) ? (
						<div style={ { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' } }>
							<Text style={ { fontSize: '12px' } }>
								{ confirmLeave
									? 'Remove this site from the sync group?'
									: 'Delete this sync group for all sites?' }
							</Text>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ confirmLeave ? leaveLinkHandler : deleteLinkHandler }
								disabled={ isSaving || isDeleting }
								isBusy={ isDeleting }
								size="compact"
							>
								Confirm
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => { setConfirmLeave( false ); setConfirmDelete( false ); } }
								size="compact"
							>
								Cancel
							</Button>
						</div>
					) : (
						<div style={ { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' } }>
							{ validationStatus && (
								<span
									title={ validationStatus.pass
										? undefined
										: validationStatus.failed_sites?.map( ( s ) => s.path ).join( ', ' )
									}
									style={ {
										fontSize:  '12px',
										color:     validationStatus.pass ? '#1a6b3a' : '#996600',
										cursor:    validationStatus.pass ? 'default' : 'help',
										flexShrink: 0,
									} }
								>
									{ validationStatus.pass
										? `All ${ validationStatus.total } posts in sync ✓`
										: `Validation issues on ${ validationStatus.failed_sites?.length ?? 1 } site(s)`
									}
								</span>
							) }
							<Button
								variant="tertiary"
								onClick={ () => setConfirmLeave( true ) }
								disabled={ isSaving || isDeleting }
								size="compact"
							>
								Leave group
							</Button>

							{ isAdmin && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => setConfirmDelete( true ) }
									disabled={ isSaving || isDeleting }
									size="compact"
								>
									Delete group
								</Button>
							) }
						</div>
					) }
				</div>
			) }
		</div>
	);
}
