const base = require( '@playwright/test' );
const wcApi = require( '@woocommerce/woocommerce-rest-api' ).default;
const { admin } = require( '../test-data/data' );
const { random } = require( '../utils/helpers' );

exports.test = base.test.extend( {
	api: async ( { baseURL }, use ) => {
		const api = new wcApi( {
			url: baseURL,
			consumerKey: process.env.CONSUMER_KEY,
			consumerSecret: process.env.CONSUMER_SECRET,
			version: 'wc/v3',
			axiosConfig: {
				// allow 404s, so we can check if a resource was deleted without try/catch
				validateStatus( status ) {
					return ( status >= 200 && status < 300 ) || status === 404;
				},
			},
		} );

		await use( api );
	},
	wcAdminApi: async ( { baseURL }, use ) => {
		const wcAdminApi = new wcApi( {
			url: baseURL,
			consumerKey: process.env.CONSUMER_KEY,
			consumerSecret: process.env.CONSUMER_SECRET,
			version: 'wc-admin', // Use wc-admin namespace
		} );

		await use( wcAdminApi );
	},

	/**
	 * Fixture for interacting with the [WordPress REST API](https://developer.wordpress.org/rest-api/reference/) endpoints.
	 *
	 * @param {{baseURL: string}} fixtures
	 * @param {function(base.APIRequestContext): Promise<void>} use
	 */
	wpApi: async ( { baseURL }, use ) => {
		const wpApi = await base.request.newContext( {
			baseURL,
			extraHTTPHeaders: {
				Authorization: `Basic ${ Buffer.from(
					`${ admin.username }:${ admin.password }`
				).toString( 'base64' ) }`,
				cookie: '',
			},
		} );

		await use( wpApi );
	},

	wcbtApi: async ( { baseURL }, use ) => {
		const wcbtApi = await base.request.newContext( {
			baseURL,
			extraHTTPHeaders: {
				Authorization: `Basic ${ Buffer.from(
					`${ admin.username }:${ admin.password }`
				).toString( 'base64' ) }`,
				cookie: '',
			},
		} );

		await use( wcbtApi );
	},

	testPageTitlePrefix: [ '', { option: true } ],

	testPage: async ( { wpApi, testPageTitlePrefix }, use ) => {
		const pageTitle = `${ testPageTitlePrefix } Page ${ random() }`.trim();
		const pageSlug = pageTitle.replace( / /gi, '-' ).toLowerCase();

		await use( { title: pageTitle, slug: pageSlug } );

		// Cleanup
		const pages = await wpApi.get(
			`./wp-json/wp/v2/pages?slug=${ pageSlug }`,
			{
				data: {
					_fields: [ 'id' ],
				},
				failOnStatusCode: false,
			}
		);

		for ( const page of await pages.json() ) {
			console.log( `Deleting page ${ page.id }` );
			await wpApi.delete( `./wp-json/wp/v2/pages/${ page.id }`, {
				data: {
					force: true,
				},
			} );
		}
	},

	testPostTitlePrefix: [ '', { option: true } ],

	testPost: async ( { wpApi, testPostTitlePrefix }, use ) => {
		const postTitle = `${ testPostTitlePrefix } Post ${ random() }`.trim();
		const postSlug = postTitle.replace( / /gi, '-' ).toLowerCase();

		await use( { title: postTitle, slug: postSlug } );

		// Cleanup
		const posts = await wpApi.get(
			`./wp-json/wp/v2/posts?slug=${ postSlug }`,
			{
				data: {
					_fields: [ 'id' ],
				},
				failOnStatusCode: false,
			}
		);

		for ( const post of await posts.json() ) {
			console.log( `Deleting post ${ post.id }` );
			await wpApi.delete( `./wp-json/wp/v2/posts/${ post.id }`, {
				data: {
					force: true,
				},
			} );
		}
	},
} );

exports.expect = base.expect;
exports.request = base.request;
exports.tags = {
	GUTENBERG: '@gutenberg',
	SERVICES: '@services',
	PAYMENTS: '@payments',
	HPOS: '@hpos',
	SKIP_ON_EXTERNAL_ENV: '@skip-on-external-env',
	SKIP_ON_WPCOM: '@skip-on-wpcom',
	SKIP_ON_PRESSABLE: '@skip-on-pressable',
	COULD_BE_LOWER_LEVEL_TEST: '@could-be-lower-level-test',
	NON_CRITICAL: '@non-critical',
	TO_BE_REMOVED: '@to-be-removed',
	NOT_E2E: '@not-e2e',
	WP_CORE: '@wp-core',
};
