wlCommentQuotation = {
	activeTextarea : null,
	onclick : function( link, qBegin, qEnd ) {
		if ( $( wlCommentQuotation.activeTextarea ).length === 0) {
			wlCommentQuotation.activeTextarea = '#wlComment';
		}
		var val = $( wlCommentQuotation.activeTextarea ).val();

		// check selection
		var quote = wlCommentQuotation.quoteSelection( link, qBegin, qEnd );
		if ( quote == '' ) {
			quote = $( link ).data( 'text' );
		}
		$( wlCommentQuotation.activeTextarea ).val( val + quote );
		$( wlCommentQuotation.activeTextarea )[0].scrollTop = $( wlCommentQuotation.activeTextarea )[0].scrollHeight;
		$( wlCommentQuotation.activeTextarea ).focus();
		return false;
	},

	quoteSelection : function ( link, qBegin, qEnd ) {
		var html = "";
		if ( typeof window.getSelection !== "undefined" ) {
			var sel = window.getSelection();
			if ( sel.rangeCount ) {
				var container = document.createElement( "div" );
				for ( var i = 0, len = sel.rangeCount; i < len; ++i ) {
					container.appendChild( sel.getRangeAt( i ).cloneContents() );
				}
				html = container.innerHTML;
			}
		} else if ( typeof document.selection !== "undefined" ) {
			if ( document.selection.type === "Text" ) {
				html = document.selection.createRange().htmlText;
			}
		}
		if ( html === '' ) {
			return '';
		}

		var $div = $( '<div></div>' );
		$div.html( html );
		var commentText = $( link ).parents( '.wl-comment' ).first().children( '.wl-comment-text'  ).html();
		if ( !wlCommentQuotation.checkText( commentText, $div ) ) {
			return '';
		}
		while (html.match(/<.+?>/i)) {
			html = html.replace(/<.+?>/i, '');
		}
		html = qBegin + html + qEnd + "\n\n";
	
		return html;
	},

	checkText : function( text, div ) {
		var result = true;
		var $div = $( div );
		if ( $div.children().length === 0 ) {
			result = result && ( text.indexOf( $div.text() ) >= 0 );
		} else {
			$div.children().each( function(){
				result = result && wlCommentQuotation.checkText( text, this );
			} );
		}
		return result;
	}
};

$( document ).ready( function(){
	$( 'textarea' )
		.focus( function(){
			wlCommentQuotation.activeTextarea = this;
		} )
		.each( function(){
			if ( $( this ).is( ':focus' )) {
				wlCommentQuotation.activeTextarea = this;
			}
		} )
	;
});
