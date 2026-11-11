vi.mock( '../../../resources/ext.readingLists.onboarding/mountApp.js', () => ( {
	default: vi.fn( () => Promise.resolve() )
} ) );

const RESOURCES = '../../../resources';
let mountApp, OnboardingPopover, MobileOnboardingPopover;
let mountOnboarding, mountDesktopOnboarding, mountMobileOnboarding;

beforeAll( async () => {
	mountApp = ( await import( `${ RESOURCES }/ext.readingLists.onboarding/mountApp.js` ) ).default;
	OnboardingPopover = ( await import( `${ RESOURCES }/ext.readingLists.onboarding/OnboardingPopover.vue` ) ).default;
	MobileOnboardingPopover = ( await import( `${ RESOURCES }/ext.readingLists.onboarding/MobileOnboardingPopover.vue` ) ).default;
	mountOnboarding = ( await import( `${ RESOURCES }/ext.readingLists.onboarding/index.js` ) ).default;
	mountDesktopOnboarding = ( await import( `${ RESOURCES }/ext.readingLists.onboarding.desktop/index.js` ) ).default;
	mountMobileOnboarding = ( await import( `${ RESOURCES }/ext.readingLists.onboarding.mobile/index.js` ) ).default;
} );

describe( 'ext.readingLists.onboarding', () => {
	test.each( [ 'desktop', 'mobile' ] )( 'mounts the %s popover', async ( variant ) => {
		await mountOnboarding( { variant, storageKey: 'key' } );

		expect( mountApp ).toHaveBeenCalledWith( expect.objectContaining( {
			component: variant === 'desktop' ? OnboardingPopover : MobileOnboardingPopover,
			storageKey: 'key'
		} ) );
	} );

	test.each( [ undefined, 'Desktop', 'toString' ] )( 'rejects unknown variant %s', async ( variant ) => {
		await expect( mountOnboarding( { variant, storageKey: 'key' } ) )
			.rejects.toThrow( 'config.variant must be' );

		expect( mountApp ).not.toHaveBeenCalled();
	} );

	test( 'ext.readingLists.onboarding.desktop mounts the desktop popover', async () => {
		await mountDesktopOnboarding( { storageKey: 'key' } );

		expect( mountApp ).toHaveBeenCalledWith(
			expect.objectContaining( { component: OnboardingPopover } )
		);
	} );

	test( 'ext.readingLists.onboarding.mobile mounts the mobile popover', async () => {
		await mountMobileOnboarding( { storageKey: 'key' } );

		expect( mountApp ).toHaveBeenCalledWith(
			expect.objectContaining( { component: MobileOnboardingPopover } )
		);
	} );
} );
