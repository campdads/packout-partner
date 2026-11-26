/**
 * Editor side of the Pack-Out Spot blocks.
 *
 * Every block renders on the server. The editor shows a placeholder for the
 * map and the server output for the Report prompt and the Team list.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var components = wp.components;
	var data = window.packoutBlocks || { categories: {}, statuses: {} };

	function options( all, values ) {
		return [ { label: all, value: '' } ].concat(
			Object.keys( values ).map( function ( value ) {
				return { label: values[ value ], value: value };
			} )
		);
	}

	wp.blocks.registerBlockType( 'packout/map', {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Map', 'packout-core' ) },
						el( components.RangeControl, {
							label: __( 'Height in pixels', 'packout-core' ),
							min: 280,
							max: 1200,
							step: 20,
							value: attributes.height,
							onChange: function ( height ) {
								props.setAttributes( { height: height } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the category and stage filters', 'packout-core' ),
							checked: attributes.showFilters,
							onChange: function ( showFilters ) {
								props.setAttributes( { showFilters: showFilters } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the search box', 'packout-core' ),
							help: __( 'Finds a town, a road, a Spot, or a Place by name, and moves the map there.', 'packout-core' ),
							checked: false !== attributes.showSearch,
							onChange: function ( showSearch ) {
								props.setAttributes( { showSearch: showSearch } );
							},
						} ),
						el( components.SelectControl, {
							label: __( 'Start on this category', 'packout-core' ),
							value: attributes.category,
							options: options( __( 'All categories', 'packout-core' ), data.categories ),
							onChange: function ( category ) {
								props.setAttributes( { category: category } );
							},
						} ),
						el( components.SelectControl, {
							label: __( 'Start on this stage', 'packout-core' ),
							value: attributes.status,
							options: options( __( 'Any stage', 'packout-core' ), data.statuses ),
							onChange: function ( status ) {
								props.setAttributes( { status: status } );
							},
						} ),
						Object.keys( data.partners || {} ).length
							? el( components.SelectControl, {
								label: __( 'Start on this partner', 'packout-core' ),
								help: __( 'The Spots on public missions of the partner\'s organizations, and the Spots credited to them.', 'packout-core' ),
								value: String( attributes.partner || 0 ),
								options: options( __( 'Every partner', 'packout-core' ), data.partners ).map( function ( option ) {
									return { label: option.label, value: option.value || '0' };
								} ),
								onChange: function ( partner ) {
									props.setAttributes( { partner: Number( partner ) || 0 } );
								},
							} )
							: null
					)
				),
				el( components.Placeholder, {
					icon: 'location-alt',
					label: __( 'Pack-Out Spot map', 'packout-core' ),
					instructions: __( 'Visitors see the published Spots here. Pending and crew-only Spots are left out.', 'packout-core' ),
				} )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.blocks.registerBlockType( 'packout/report-prompt', {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Report prompt', 'packout-core' ) },
						el( components.TextControl, {
							label: __( 'Heading', 'packout-core' ),
							value: attributes.heading,
							onChange: function ( heading ) {
								props.setAttributes( { heading: heading } );
							},
						} ),
						el( components.TextareaControl, {
							label: __( 'Introduction', 'packout-core' ),
							value: attributes.text,
							onChange: function ( text ) {
								props.setAttributes( { text: text } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the report form', 'packout-core' ),
							help: __( 'The form is chosen in Pack-Out Spot settings.', 'packout-core' ),
							checked: attributes.showForm,
							onChange: function ( showForm ) {
								props.setAttributes( { showForm: showForm } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Link to the field app', 'packout-core' ),
							checked: attributes.showFieldApp,
							onChange: function ( showFieldApp ) {
								props.setAttributes( { showFieldApp: showFieldApp } );
							},
						} )
					)
				),
				el( components.Disabled, null, el( wp.serverSideRender, { block: 'packout/report-prompt', attributes: attributes } ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.blocks.registerBlockType( 'packout/teams', {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Organization list', 'packout-core' ) },
						el( components.ToggleControl, {
							label: __( 'Show how many missions each organization has shared', 'packout-core' ),
							help: __( 'Only missions a lead marked public are counted.', 'packout-core' ),
							checked: attributes.showMissions,
							onChange: function ( showMissions ) {
								props.setAttributes( { showMissions: showMissions } );
							},
						} )
					)
				),
				el( components.Disabled, null, el( wp.serverSideRender, { block: 'packout/teams', attributes: attributes } ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.blocks.registerBlockType( 'packout/impact', {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Impact summary', 'packout-core' ) },
						el( components.ToggleControl, {
							label: __( 'Show the line above the figures', 'packout-core' ),
							help: __( 'The line is chosen under Pack-Out Spot, Settings.', 'packout-core' ),
							checked: attributes.showLine,
							onChange: function ( showLine ) {
								props.setAttributes( { showLine: showLine } );
							},
						} )
					)
				),
				el( components.Disabled, null, el( wp.serverSideRender, { block: 'packout/impact', attributes: attributes } ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.blocks.registerBlockType( 'packout/account', {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'My account', 'packout-core' ) },
						el( components.SelectControl, {
							label: __( 'Part to show', 'packout-core' ),
							help: __( 'Put the overview on the My account page, and each part on a page under it.', 'packout-core' ),
							value: attributes.view || '',
							options: [
								{ label: __( 'Overview', 'packout-core' ), value: '' },
								{ label: __( 'Personal info', 'packout-core' ), value: 'personal' },
								{ label: __( 'My impact', 'packout-core' ), value: 'impact' },
								{ label: __( 'My contributions', 'packout-core' ), value: 'contributions' },
								{ label: __( 'Email preferences', 'packout-core' ), value: 'email' },
								{ label: __( 'Sign-in details', 'packout-core' ), value: 'signin' },
							],
							onChange: function ( view ) {
								props.setAttributes( { view: view } );
							},
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( components.Disabled, null, el( wp.serverSideRender, { block: 'packout/account', attributes: attributes } ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	// The calendar: one block, offered in the inserter under each of its six forms.
	var calendarViews = [
		{ name: 'month', title: __( 'Calendar: Month', 'packout-core' ), description: __( 'A month of missions, campaigns, and programs, as a grid of weeks.', 'packout-core' ) },
		{ name: 'week', title: __( 'Calendar: Week', 'packout-core' ), description: __( 'Seven days as columns, with timed missions on an hour scale.', 'packout-core' ) },
		{ name: 'day', title: __( 'Calendar: Day', 'packout-core' ), description: __( 'One day, with timed missions on an hour scale.', 'packout-core' ) },
		{ name: 'agenda', title: __( 'Calendar: Agenda', 'packout-core' ), description: __( 'A list grouped by day, from a start date forward.', 'packout-core' ) },
		{ name: 'upcoming', title: __( 'Calendar: Upcoming', 'packout-core' ), description: __( 'The next missions, campaigns, and programs, as cards.', 'packout-core' ) },
		{ name: 'past', title: __( 'Calendar: Past', 'packout-core' ), description: __( 'The last finished missions, newest first, with how many Spots each cleaned.', 'packout-core' ) },
	];
	var calendarData = data.calendar || {};
	var calendarKinds = [
		{ value: 'missions', label: __( 'Missions', 'packout-core' ) },
		{ value: 'campaigns', label: __( 'Campaigns', 'packout-core' ) },
		{ value: 'programs', label: __( 'Programs', 'packout-core' ) },
	];

	function calendarRecord( props, name, label, all, choices ) {
		if ( ! Object.keys( choices || {} ).length && ! props.attributes[ name ] ) {
			return null;
		}
		return el( components.SelectControl, {
			label: label,
			value: String( props.attributes[ name ] || 0 ),
			options: options( all, choices || {} ).map( function ( option ) {
				return { label: option.label, value: option.value || '0' };
			} ),
			onChange: function ( value ) {
				var change = {};
				change[ name ] = Number( value ) || 0;
				props.setAttributes( change );
			},
			__nextHasNoMarginBottom: true,
		} );
	}

	wp.blocks.registerBlockType( 'packout/calendar', {
		variations: calendarViews.map( function ( view ) {
			return {
				name: view.name,
				title: view.title,
				description: view.description,
				attributes: { view: view.name },
				isDefault: 'month' === view.name,
				scope: [ 'inserter', 'transform' ],
				isActive: [ 'view' ],
			};
		} ),
		edit: function ( props ) {
			var attributes = props.attributes;
			var moves = [ 'day', 'week', 'month', 'agenda' ].indexOf( attributes.view ) !== -1;
			var show = attributes.show && attributes.show.length ? attributes.show : [ 'missions', 'campaigns', 'programs' ];
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Calendar', 'packout-core' ) },
						el( components.SelectControl, {
							label: __( 'Form', 'packout-core' ),
							value: attributes.view,
							options: [
								{ label: __( 'Month', 'packout-core' ), value: 'month' },
								{ label: __( 'Week', 'packout-core' ), value: 'week' },
								{ label: __( 'Day', 'packout-core' ), value: 'day' },
								{ label: __( 'Agenda', 'packout-core' ), value: 'agenda' },
								{ label: __( 'Upcoming', 'packout-core' ), value: 'upcoming' },
								{ label: __( 'Past', 'packout-core' ), value: 'past' },
							],
							onChange: function ( view ) {
								props.setAttributes( { view: view } );
							},
							__nextHasNoMarginBottom: true,
						} ),
						moves
							? el( components.ToggleControl, {
								label: __( 'Let visitors change the form', 'packout-core' ),
								help: __( 'Adds Day, Week, Month, and Agenda, and the category and organization filters.', 'packout-core' ),
								checked: false !== attributes.switcher,
								onChange: function ( switcher ) {
									props.setAttributes( { switcher: switcher } );
								},
								__nextHasNoMarginBottom: true,
							} )
							: null,
						'day' !== attributes.view && 'week' !== attributes.view && 'month' !== attributes.view
							? el( components.RangeControl, {
								label: __( 'How many to show', 'packout-core' ),
								min: 1,
								max: 50,
								value: attributes.count,
								onChange: function ( count ) {
									props.setAttributes( { count: count } );
								},
								__nextHasNoMarginBottom: true,
							} )
							: null,
						'past' !== attributes.view
							? el( components.ToggleControl, {
								label: __( 'Show the Subscribe link', 'packout-core' ),
								help: __( 'A calendar app that subscribes is sent the public events only.', 'packout-core' ),
								checked: false !== attributes.feed,
								onChange: function ( feed ) {
									props.setAttributes( { feed: feed } );
								},
								__nextHasNoMarginBottom: true,
							} )
							: null,
						'week' === attributes.view || 'month' === attributes.view || ( moves && false !== attributes.switcher )
							? el( components.SelectControl, {
								label: __( 'Week starts on', 'packout-core' ),
								value: attributes.weekStart,
								options: [
									{ label: __( 'The day set for this site', 'packout-core' ), value: 'site' },
									{ label: __( 'Sunday', 'packout-core' ), value: 'sunday' },
									{ label: __( 'Monday', 'packout-core' ), value: 'monday' },
								],
								onChange: function ( weekStart ) {
									props.setAttributes( { weekStart: weekStart } );
								},
								__nextHasNoMarginBottom: true,
							} )
							: null
					),
					el(
						components.PanelBody,
						{ title: __( 'What is on it', 'packout-core' ), initialOpen: false },
						calendarKinds.map( function ( kind ) {
							return el( components.CheckboxControl, {
								key: kind.value,
								label: kind.label,
								checked: show.indexOf( kind.value ) !== -1,
								onChange: function ( checked ) {
									var next = calendarKinds
										.map( function ( each ) {
											return each.value;
										} )
										.filter( function ( value ) {
											return value === kind.value ? checked : show.indexOf( value ) !== -1;
										} );
									// A calendar of nothing is not offered. Unticking the last one ticks them all.
									props.setAttributes( { show: next.length ? next : [ 'missions', 'campaigns', 'programs' ] } );
								},
								__nextHasNoMarginBottom: true,
							} );
						} ),
						calendarRecord( props, 'partner', __( 'Partner', 'packout-core' ), __( 'Every partner', 'packout-core' ), calendarData.partners ),
						calendarRecord( props, 'organization', __( 'Organization', 'packout-core' ), __( 'Every organization', 'packout-core' ), calendarData.organizations ),
						calendarRecord( props, 'program', __( 'Program', 'packout-core' ), __( 'Every program', 'packout-core' ), calendarData.programs ),
						calendarRecord( props, 'campaign', __( 'Campaign', 'packout-core' ), __( 'Every campaign', 'packout-core' ), calendarData.campaigns ),
						el( components.SelectControl, {
							label: __( 'Category', 'packout-core' ),
							help: __( 'Missions with a Spot of this category. Campaigns and programs are left out.', 'packout-core' ),
							value: attributes.category,
							options: options( __( 'All categories', 'packout-core' ), data.categories ),
							onChange: function ( category ) {
								props.setAttributes( { category: category } );
							},
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( components.Disabled, null, el( wp.serverSideRender, { block: 'packout/calendar', attributes: attributes } ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	// The partner badge, on a partner site. The hub does not have it.
	if ( ( data.absent || [] ).indexOf( 'packout/partner-badge' ) === -1 ) {
		wp.blocks.registerBlockType( 'packout/partner-badge', {
			edit: function ( props ) {
				var attributes = props.attributes;
				return el(
					'div',
					useBlockProps(),
					el(
						InspectorControls,
						null,
						el(
							components.PanelBody,
							{ title: __( 'Partner badge', 'packout-core' ) },
							el( components.ToggleControl, {
								label: __( 'Show this site\'s totals under the badge', 'packout-core' ),
								help: __( 'Spots cleaned and verified hours, from the records a coordinator here counts publicly.', 'packout-core' ),
								checked: !! attributes.showTotals,
								onChange: function ( showTotals ) {
									props.setAttributes( { showTotals: showTotals } );
								},
							} )
						)
					),
					el( components.Disabled, null, el( wp.serverSideRender, { block: 'packout/partner-badge', attributes: attributes } ) )
				);
			},
			save: function () {
				return null;
			},
		} );
	}

	// Sign up, Sign in, the workspaces, the Place list, and the Partner list have no options. The editor shows a preview.
	// A partner site has neither of the two lists: they are the hub's.
	var absent = data.absent || [];
	[ 'packout/signup', 'packout/signin', 'packout/workspace', 'packout/places', 'packout/partners', 'packout/partner-workspace' ].forEach( function ( name ) {
		if ( absent.indexOf( name ) !== -1 ) {
			return;
		}
		wp.blocks.registerBlockType( name, {
			edit: function () {
				return el( 'div', useBlockProps(), el( components.Disabled, null, el( wp.serverSideRender, { block: name } ) ) );
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp );
