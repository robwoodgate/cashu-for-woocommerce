const settings = window.wc.wcSettings.getSetting('cashu_data', {});
const label = window.wp.htmlEntities.decodeEntities(settings.title || 'Cashu ecash');

const Content = () => {
	return window.wp.htmlEntities.decodeEntities(settings.description || '');
};

const CashuBlockGateway = {
	name: 'cashu',
	label: label,
	content: window.wp.element.createElement(Content, null),
	edit: window.wp.element.createElement(Content, null),
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports || [],
	},
};

window.wc.wcBlocksRegistry.registerPaymentMethod(CashuBlockGateway);
