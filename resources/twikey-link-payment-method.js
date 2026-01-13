{
  const { getSetting } = window.wc.wcSettings;
  const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
  const { createElement } = window.wp.element;

  const settings = getSetting('twikey-paylink_data')

  const Label = () => {
    return createElement('span', {
      style: { display: 'flex', alignItems: 'center', gap: '8px' }
    }, [
      settings.title,
      createElement(
        'img',
        {
          src: 'https://www.twikey.com/img/butterfly.svg',
          alt: 'Twikey Logo',
          style: { height: '1em', display: 'inline' }
        })
    ])
  }

  const Content = () => {
    return createElement('div', null, settings.description);
  }

  registerPaymentMethod({
    name: 'twikey-paylink',
    title: settings.title,
    description: settings.description,
    label: createElement(Label),
    ariaLabel: settings.title,
    content: createElement(Content),
    edit: createElement(Content),
    canMakePayment: (options) => true,
    supports: {
      features: settings.supports,
    }
  })
}
