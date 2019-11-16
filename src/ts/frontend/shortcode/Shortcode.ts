/* exported Shortcode */
const justifiedLayout = require( 'justified-layout' );

class Shortcode {
	private readonly container: JQuery;
	private readonly hash: string;
	private readonly shortHash: string;

	private lightbox: JQuery = $();
	private hasMore = false;
	private path = '';
	private lastPage = 1;
	private loading = false;

	public constructor( container: HTMLElement, hash: string ) {
		this.container = $( container );
		this.hash = hash;
		this.shortHash = hash.substr( 0, 8 );
		this.init();
		$( window ).on( 'popstate', () => this.init() );
		$( window ).resize( () => this.reflow() );
	}

	public onLightboxNavigation( e: JQuery ): void {
		const page = $( e ).data( 'sgdg-page' );
		const children = $( e ).parent().children().length;
		history.replaceState( history.state, '', addQueryParameter( this.shortHash, 'page', page ) );
		if ( 'true' === sgdgShortcodeLocalize.page_autoload && this.hasMore && $( e ).index() >= Math.min( children - 2, Math.floor( 0.9 * children ) ) ) {
			this.add();
		}
	}

	public reflow(): void {
		const loaded: Array<boolean> = [];
		const ratios: Array<number> = [];
		this.container.find( '.sgdg-gallery' ).children().each( ( i, child ) => {
			$( child ).css( 'display', 'inline-block' );
			const image = child.firstChild as HTMLImageElement;
			let ratio = image.naturalWidth / image.naturalHeight;
			if ( 0 < $( child ).find( 'svg' ).length ) {
				const bbox = ( $( child ).find( 'svg' )[ 0 ] as unknown as SVGGraphicsElement ).getBBox();
				ratio = bbox.width / bbox.height;
			}
			if ( $( child ).hasClass( 'sgdg-grid-square' ) ) {
				ratio = 1;
			}
			if ( isNaN( ratio ) ) {
				loaded[ i ] = false;
			} else {
				loaded[ i ] = true;
				ratios.push( ratio );
			}
			$( child ).css( 'position', 'absolute' );
		} );
		if ( 0 < ratios.length ) {
			this.container.find( '.sgdg-loading' ).remove();
		}
		const positions = justifiedLayout( ratios, {
			containerWidth: this.container.find( '.sgdg-gallery' ).width(),
			containerPadding: { top: 10, left: 0, right: 0, bottom: 0 },
			boxSpacing: parseInt( sgdgShortcodeLocalize.grid_spacing ),
			targetRowHeight: parseInt( sgdgShortcodeLocalize.grid_height ),
			targetRowHeightTolerance: 0.15,
			edgeCaseMinRowHeight: 0,
		} );
		let j = 0;
		this.container.find( '.sgdg-gallery' ).children().each( ( i, child ) => {
			if ( ! loaded[ i ] ) {
				$( child ).css( 'display', 'none' );
				return;
			}
			const box = positions.boxes[ j ];
			const containerPosition = this.container.find( '.sgdg-gallery' ).position();
			$( child ).css( 'top', box.top + containerPosition.top );
			$( child ).css( 'left', box.left + containerPosition.left );
			$( child ).width( box.width );
			$( child ).height( box.height );
			j++;
		} );
		this.container.find( '.sgdg-gallery' ).height( positions.containerHeight );
	}

	private reflowTimer(): void {
		ShortcodeRegistry.reflowAll();
		if ( this.loading ) {
			setTimeout( () => {
				this.reflowTimer();
			}, 250 );
		}
	}

	private init(): void {
		const newPath = getQueryParameter( this.shortHash, 'path' );
		if ( this.path !== newPath ) {
			this.path = newPath;
			this.get();
		}
	}

	private get(): void {
		this.path = getQueryParameter( this.shortHash, 'path' );
		this.lastPage = parseInt( getQueryParameter( this.shortHash, 'page' ) ) || 1;
		this.lightbox = $().imageLightbox( {
			allowedTypes: '',
			animationSpeed: parseInt( sgdgShortcodeLocalize.preview_speed, 10 ),
			activity: ( 'true' === sgdgShortcodeLocalize.preview_activity ),
			arrows: ( 'true' === sgdgShortcodeLocalize.preview_arrows ),
			button: ( 'true' === sgdgShortcodeLocalize.preview_closebutton ),
			fullscreen: true,
			history: true,
			overlay: true,
			caption: ( 'true' === sgdgShortcodeLocalize.preview_captions ),
			quitOnEnd: ( 'true' === sgdgShortcodeLocalize.preview_quitOnEnd ),
		} );
		this.container.find( '.sgdg-gallery' ).replaceWith( '<div class="sgdg-loading"><div></div></div>' );
		this.container.find( '.sgdg-more-button' ).remove();
		ShortcodeRegistry.reflowAll();
		$.get( sgdgShortcodeLocalize.ajax_url, {
			action: 'gallery',
			hash: this.hash,
			path: this.path,
			page: this.lastPage,
		}, ( data: GalleryResponse ) => {
			if ( isError( data ) ) {
				this.container.html( data.error );
				return;
			}
			this.getSuccess( data );
		} );
	}

	private getSuccess( data: GallerySuccessResponse ): void {
		const pageLength = ( ( data.directories ? data.directories.length : 0 ) + ( data.images ? data.images.length : 0 ) + ( data.videos ? data.videos.length : 0 ) ) / this.lastPage;
		let html = '';
		let currentPage = 1;
		let remaining = pageLength;
		if ( ( data.path && 0 < data.path.length ) || 0 < data.directories.length ) {
			html += this.renderBreadcrumbs( data.path );
		}
		if ( 0 < data.directories.length || 0 < data.images.length || 0 < data.videos.length ) {
			html += '<div class="sgdg-loading">' +
				'<div></div>' +
			'</div>' +
			'<div class="sgdg-gallery">';
			if ( data.directories ) {
				$.each( data.directories, ( _, directory ) => {
					html += this.renderDirectory( directory );
					remaining--;
					if ( 0 === remaining ) {
						remaining = pageLength;
						currentPage++;
					}
				} );
			}
			if ( data.images ) {
				$.each( data.images, ( _, image ) => {
					html += this.renderImage( currentPage, image );
					remaining--;
					if ( 0 === remaining ) {
						remaining = pageLength;
						currentPage++;
					}
				} );
			}
			if ( data.videos ) {
				$.each( data.videos, ( _, video ) => {
					if ( '' !== document.createElement( 'video' ).canPlayType( video.mimeType ) ) {
						html += this.renderVideo( currentPage, video );
					}
					remaining--;
					if ( 0 === remaining ) {
						remaining = pageLength;
						currentPage++;
					}
				} );
			}
			html += '</div>';
			if ( data.more ) {
				html += this.renderMoreButton();
			}
		} else {
			html += '<div class="sgdg-gallery">' + sgdgShortcodeLocalize.empty_gallery + '</div>';
		}
		this.container.html( html );
		this.hasMore = data.more;
		this.postLoad();
		this.lightbox.openHistory();
	}

	private add(): void {
		this.lastPage += 1;
		this.container.find( '.sgdg-gallery' ).after( '<div class="sgdg-loading">' +
			'<div></div>' +
		'</div>' );
		this.container.find( '.sgdg-more-button' ).remove();
		$.get( sgdgShortcodeLocalize.ajax_url, {
			action: 'page',
			hash: this.hash,
			path: getQueryParameter( this.shortHash, 'path' ),
			page: this.lastPage,
		}, ( data: PageResponse ) => {
			if ( isError( data ) ) {
				this.container.find( '.sgdg-loading' ).replaceWith( data.error );
				this.container.find( '.sgdg-more-button' ).remove();
				return;
			}
			this.addSuccess( data );
		} );
	}

	private addSuccess( data: PageSuccessResponse ): void {
		let html = '';
		$.each( data.directories, ( _, directory ) => {
			html += this.renderDirectory( directory );
		} );
		$.each( data.images, ( _, image ) => {
			html += this.renderImage( this.lastPage, image );
		} );
		$.each( data.videos, ( _, video ) => {
			html += this.renderVideo( this.lastPage, video );
		} );
		this.container.find( '.sgdg-gallery' ).append( html );
		this.hasMore = data.more;
		if ( data.more ) {
			this.container.append( this.renderMoreButton() );
		}
		this.container.find( '.sgdg-loading' ).remove();
		this.postLoad();
	}

	private postLoad(): void {
		this.container.find( 'a[data-sgdg-path]' ).off( 'click' ).click( () => {
			history.pushState( {}, '', addQueryParameter( this.shortHash, 'path', this.path ) );
			this.get(); // eslint-disable-line @typescript-eslint/no-use-before-define
			return false;
		} );
		this.container.find( '.sgdg-more-button' ).click( () => {
			this.add(); // eslint-disable-line @typescript-eslint/no-use-before-define
			return false;
		} );

		this.loading = true;
		this.container.find( '.sgdg-gallery' ).imagesLoaded( { background: true }, () => {
			this.loading = false;
			ShortcodeRegistry.reflowAll();
		} );
		this.reflowTimer();

		this.lightbox.addToImageLightbox( this.container.find( 'a[data-imagelightbox]' ) );
		if ( 'true' === sgdgShortcodeLocalize.page_autoload ) {
			$( window ).off( 'scroll' ).scroll( ( event ) => {
				const el = $( '.sgdg-more-button' );
				if ( undefined === el.offset() ) {
					return;
				}
				const inView = $( event.currentTarget ).scrollTop()! + $( window ).height()! > el.offset()!.top + el.outerHeight()!;
				if ( inView && ! this.loading ) {
					this.add(); // eslint-disable-line @typescript-eslint/no-use-before-define
				}
			} );
		}
	}

	private renderBreadcrumbs( path: Array<PartialDirectory> ): string {
		let html = '<div>' +
			'<a data-sgdg-path="" href="' + removeQueryParameter( this.shortHash, 'path' ) + '">' + sgdgShortcodeLocalize.breadcrumbs_top + '</a>';
		let field = '';
		$.each( path, ( _, crumb ) => {
			field += crumb.id;
			html += ' >' +
				'<a data-sgdg-path="' + field + '" href="' + addQueryParameter( this.shortHash, 'path', field ) + '">' + crumb.name + '</a>';
			field += '/';
		} );
		html += '</div>';
		return html;
	}

	private renderDirectory( directory: Directory ): string {
		let newPath = getQueryParameter( this.shortHash, 'path' );
		newPath = ( newPath ? newPath + '/' : '' ) + directory.id;
		let html = '<a class="sgdg-grid-a sgdg-grid-square" data-sgdg-path="' + newPath + '" href="' + addQueryParameter( this.shortHash, 'path', newPath ) + '"';
		if ( directory.thumbnail ) {
			html += ' style="background-image: url(\'' + directory.thumbnail + '\');">';
		} else {
			html += '>' +
				'<svg class="sgdg-dir-icon" x="0px" y="0px" focusable="false" viewBox="0 0 24 24" fill="#8f8f8f">' +
					'<path d="M10 4H4c-1.1 0-2 .9-2 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"></path>' +
				'</svg>';
		}
		html += '<div class="sgdg-dir-overlay"><div class="sgdg-dir-name">' + directory.name + '</div>';
		if ( directory.dircount ) {
			html += '<span class="sgdg-count-icon dashicons dashicons-category"></span> ' +
				directory.dircount + ( 1000 === directory.dircount ? '+' : '' );
		}
		if ( directory.imagecount ) {
			let iconClass = '';
			if ( directory.dircount ) {
				iconClass = ' sgdg-count-icon-indent';
			}
			html += '<span class="sgdg-count-icon dashicons dashicons-format-image' + iconClass + '"></span> ' +
				directory.imagecount + ( 1000 === directory.imagecount ? '+' : '' );
		}
		if ( directory.videocount ) {
			let iconClass = '';
			if ( directory.dircount || directory.imagecount ) {
				iconClass = ' sgdg-count-icon-indent';
			}
			html += '<span class="sgdg-count-icon dashicons dashicons-video-alt3' + iconClass + '"></span> ' +
				directory.videocount + ( 1000 === directory.videocount ? '+' : '' );
		}
		html += '</div>' +
		'</a>';
		return html;
	}

	private renderImage( page: number, image: Image ): string {
		return '<a class="sgdg-grid-a" data-imagelightbox="' + this.shortHash + '" ' +
			'data-ilb2-id="' + image.id + '" ' +
			'data-ilb2-caption="' + image.description + '" ' +
			'data-sgdg-page="' + page + '" ' +
			'href="' + image.image + '">' +
				'<img class="sgdg-grid-img" src="' + image.thumbnail + '">' +
		'</a>';
	}

	private renderVideo( page: number, video: Video ): string {
		return '<a class="sgdg-grid-a" data-imagelightbox="' + this.shortHash + '" ' +
			'data-ilb2-id="' + video.id + '" ' +
			'data-sgdg-page="' + page + '" ' +
			'data-ilb2-video=\'{ "controls": "controls", "autoplay": "autoplay", "sources": [ { "src": "' + video.src + '", "type": "' + video.mimeType + '" } ] }\'>' +
				'<img class="sgdg-grid-img" src="' + video.thumbnail + '">' +
		'</a>';
	}

	private renderMoreButton(): string {
		return '<div class="sgdg-more-button">' +
			'<div>' + sgdgShortcodeLocalize.load_more + '</div>' +
		'</div>';
	}
}
