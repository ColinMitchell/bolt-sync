import { useState, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { FormToggle, Button, ComboboxControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { getRelativeTime } from '../utils/date';

/**
 * Resolve the pathname from a permalink URL, falling back to site.path.
 *
 * @param {string|null} permalink
 * @param {string}      fallback
 * @return {string}
 */
function pathFromPermalink( permalink, fallback ) {
	if ( ! permalink ) return fallback;
	try { return new URL( permalink ).pathname; } catch { return fallback; }
}

/**
 * Renders one toggle row per network site.
 *
 * Layout per row: [toggle] [path / edit-link]  [badge or "Change post" button]
 * "Synced X ago" and status hints surface as title-tooltips on the path.
 *
 * @param {{ sites: object[], setSites: Function, isSaving: boolean, linkId: number|null, sitesHistory: object[]|null }} props
 */
export default function SitesSelector( { sites, setSites, isSaving, linkId, sitesHistory } ) {
	const [ expandedBlogId, setExpandedBlogId ] = useState( null );
	const [ postOptions, setPostOptions ]       = useState( [] );
	const [ isFetchingPosts, setIsFetchingPosts ] = useState( false );

	const currentPostSlug = useSelect(
		( select ) => {
			const editor = select( 'core/editor' );
			const post   = editor.getCurrentPost();
			// Gutenberg populates fields differently by status:
			// - published:       slug is set
			// - draft/auto-draft: slug is often empty; generated_slug is derived from title
			// - getEditedPostAttribute reads the live editor state (may lead over getCurrentPost)
			return editor.getEditedPostAttribute( 'slug' )
				|| post?.slug
				|| post?.generated_slug
				|| null;
		},
		[]
	);

	const toggleSite = useCallback( ( targetSite ) => {
		setSites( ( prev ) => prev.map( ( site ) => {
			if ( site.blog_id !== targetSite.blog_id ) return site;

			const turningOn = ! site.isLink;
			return {
				...site,
				isLink:    turningOn,
				link_info: {
					...site.link_info,
					active:  turningOn,
					...( turningOn && targetSite.matching_post ? {
						post_id: targetSite.matching_post.ID,
						post:    targetSite.matching_post,
					} : {} ),
					...( ! turningOn ? { post_id: 0, post: null } : {} ),
				},
			};
		} ) );
	}, [ setSites ] );

	const openExistingPicker = useCallback( async ( site ) => {
		if ( expandedBlogId === site.blog_id ) {
			setExpandedBlogId( null );
			return;
		}

		setExpandedBlogId( site.blog_id );
		setPostOptions( [] );
		setIsFetchingPosts( true );

		try {
			const postType       = wp.data.select( 'core/editor' ).getCurrentPostType();
			const postTypeObject = wp.data.select( 'core' ).getPostType( postType );

			const posts = await apiFetch( {
				url: `${ window.location.origin }${ site.path }wp-json/wp/v2/${ postTypeObject.rest_base }?per_page=100&status=publish`,
			} );

			setPostOptions( ( posts ?? [] ).map( ( p ) => ( {
				label: `${ new URL( p.link ).pathname }  (${ p.id })`,
				value: String( p.id ),
				post:  p,
			} ) ) );
		} catch {
			setPostOptions( [] );
		} finally {
			setIsFetchingPosts( false );
		}
	}, [ expandedBlogId ] );

	const selectExistingPost = useCallback( ( targetSite, value ) => {
		if ( ! value ) return;
		const selected = postOptions.find( ( o ) => o.value === value );
		if ( ! selected ) return;

		// REST API shape uses `link` and lowercase `id`; normalise to match the
		// PHP-rendered post object shape the rest of the component expects.
		const post = {
			...selected.post,
			ID:        selected.post.id,
			permalink: selected.post.link ?? selected.post.permalink,
		};

		setSites( ( prev ) => prev.map( ( site ) => {
			if ( site.blog_id !== targetSite.blog_id ) return site;
			return {
				...site,
				isLink:    true,
				link_info: {
					...site.link_info,
					active:  true,
					post_id: Number( value ),
					post,
				},
			};
		} ) );

		setExpandedBlogId( null );
	}, [ postOptions, setSites ] );

	return (
		<div style={ { display: 'flex', flexDirection: 'column' } }>
			{ sites.map( ( site ) => {
				const isCurrentSite   = site.is_current_site;
				const isExternalGroup = ! linkId && !! site.matching_link_id;
				const isConflictGroup = !! linkId && !! site.matching_link_id && site.matching_link_id !== linkId;
				const isLinked        = !! ( site.isLink && site.link_info?.active );
				const isChecked       = isLinked || isExternalGroup;
				const isDisabled      = isSaving || isCurrentSite || isExternalGroup || isConflictGroup;
				const pickerOpen      = expandedBlogId === site.blog_id;

				const post     = site.link_info?.post;
				const editLink = post?.edit_link?.replace( /&amp;/g, '&' ) ?? null;
				const synced   = post?.post_modified
					? getRelativeTime( new Date( post.post_modified + 'Z' ) )
					: null;

				// Resolve display path.
				// For the current site: always use slug from the editor store or PHP post_name.
				// get_permalink() returns a preview URL for drafts, so we never use rawPermalink here.
				// For other sites: use the stored permalink (their posts are likely published).
				let displayPath;
				if ( isCurrentSite ) {
					const slug = currentPostSlug || site.post_name || '';
					displayPath = slug ? `${ site.path }${ slug }/` : site.path;
				} else {
					const rawPermalink = post?.permalink ?? site.matching_post?.permalink ?? null;
					displayPath = pathFromPermalink( rawPermalink, site.path );
				}

				// Detect a post swap via "Change post" picker — show old path struck through.
				const historySite    = sitesHistory?.find( ( s ) => s.blog_id === site.blog_id );
				const origPostId     = historySite?.link_info?.post_id ?? 0;
				const currPostId     = site.link_info?.post_id ?? 0;
				const postSwapped    = !! origPostId && !! currPostId && origPostId !== currPostId;
				const origPath       = postSwapped
					? pathFromPermalink( historySite?.link_info?.post?.permalink, site.path )
					: null;

				// When toggled ON with no existing post, show a speculative path so
				// the user knows where the duplicate will land.
				const willCreate  = isLinked && ! post && ! postSwapped;
				const speculativePath = willCreate && currentPostSlug
					? `${ site.path }${ currentPostSlug }/`
					: null;

				// Tooltip text.
				let tooltip;
				if ( isExternalGroup ) {
					tooltip = 'Part of an existing group — saving will join it';
				} else if ( isConflictGroup ) {
					tooltip = 'Already in a different group';
				} else if ( synced ) {
					tooltip = `Synced ${ synced }`;
				}

				// Path element.
				let pathContent;
				if ( postSwapped ) {
					pathContent = (
						<>
							<span style={ { textDecoration: 'line-through', color: '#757575', marginRight: '4px' } }>
								{ origPath }
							</span>
							{ displayPath }
						</>
					);
				} else if ( willCreate ) {
					pathContent = (
						<span style={ { color: '#757575', fontStyle: 'italic' } }>
							{ speculativePath ?? displayPath }
						</span>
					);
				} else {
					pathContent = displayPath;
				}

				const pathEl = ( ! isCurrentSite && isLinked && editLink && ! postSwapped && ! willCreate ) ? (
					<a
						href={ editLink }
						target="_blank"
						rel="noreferrer"
						title={ tooltip }
						style={ { color: 'inherit', textDecoration: 'underline', textUnderlineOffset: '2px' } }
					>
						{ displayPath }
					</a>
				) : (
					<span title={ tooltip }>{ pathContent }</span>
				);

				const showLinkPicker = ! isCurrentSite && ! isExternalGroup && ! isConflictGroup && isChecked;

				return (
					<div
						key={ site.blog_id }
						style={ {
							borderLeft:  isCurrentSite ? '3px solid #007cba' : '3px solid transparent',
							paddingLeft: '8px',
						} }
					>
						<div style={ {
							display:    'flex',
							alignItems: 'center',
							gap:        '8px',
							padding:    '5px 0',
							minHeight:  '36px',
						} }>
							<FormToggle
								checked={ isChecked }
								disabled={ isDisabled }
								onChange={ () => toggleSite( site ) }
							/>

							<span style={ { flex: 1, fontSize: '13px', lineHeight: 1.4 } }>
								{ pathEl }
							</span>

							{ isCurrentSite && (
								<span style={ {
									fontSize:     '11px',
									fontWeight:   600,
									background:   '#dcdcde',
									borderRadius: '2px',
									padding:      '1px 6px',
									whiteSpace:   'nowrap',
								} }>
									Current Site
								</span>
							) }

							{ willCreate && (
								<span style={ {
									fontSize:     '11px',
									fontWeight:   600,
									background:   '#d8f0e0',
									color:        '#1a6b3a',
									borderRadius: '2px',
									padding:      '1px 6px',
									whiteSpace:   'nowrap',
								} }>
									New post
								</span>
							) }

							{ showLinkPicker && (
								<Button
									variant="tertiary"
									size="compact"
									onClick={ () => openExistingPicker( site ) }
									disabled={ isSaving }
									style={ { fontSize: '11px', flexShrink: 0 } }
								>
									{ pickerOpen ? 'Cancel' : 'Change post' }
								</Button>
							) }
						</div>

						{ pickerOpen && (
							<div style={ { paddingBottom: '8px', paddingLeft: '40px' } }>
								<ComboboxControl
									__nextHasNoMarginBottom
									label=""
									hideLabelFromVision
									value={ site.link_info?.post_id ? String( site.link_info.post_id ) : null }
									options={ postOptions }
									onChange={ ( val ) => selectExistingPost( site, val ) }
									isLoading={ isFetchingPosts }
									placeholder="Search posts…"
								/>
							</div>
						) }
					</div>
				);
			} ) }
		</div>
	);
}
