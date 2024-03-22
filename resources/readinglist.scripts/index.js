const { getReadingListUrl } = require( './utils.js' );
const special = require( './special.js' );
const Vue = require( 'vue' );

$( function () {
	const title = mw.config.get( 'wgTitle' ),
		params = title.split( '/' ).slice( 1 ),
		ownerName = params[ 0 ],
		collectionID = params.length > 1 ?
			parseInt( params[ 1 ], 10 ) : null;

	const importValue = mw.util.getParamValue( 'limport', location.search );
	const exportValue = mw.util.getParamValue( 'lexport', location.search );
	const isImport = !!importValue;
	if ( importValue || exportValue ) {
		special.init( Vue, null, -1, importValue || exportValue, isImport );
		return;
	} else if ( params.length === 0 ) {
		window.location.pathname = getReadingListUrl( mw.user.getName() );
	} else {
		special.init( Vue, ownerName, collectionID );
	}
} );
