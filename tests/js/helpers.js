/**
 * Shared plumbing for the page tests.
 *
 * The Activity Control Panel is read-only: it renders, it links, it never
 * mutates. So the router-capture and 422-rejection helpers the sibling addons
 * carry have no caller here and are deliberately absent — an unused helper is
 * a future test written against the wrong seam. What is left is the pair that
 * is addon-independent: locate a rendered thing by what the template labelled
 * it with, and read everything the page is showing.
 */

/**
 * Finds the component of this name carrying this label.
 *
 * The stubs render every scalar attribute, so a component is located by what
 * the template handed it rather than by its position in a list.
 */
export function findByAttr(wrapper, name, attribute, value) {
    return wrapper.findAllComponents({ name })
        .find((candidate) => candidate.attributes(`data-attr-${attribute}`) === value);
}

/**
 * Everything the page is showing the user, as one string.
 *
 * Text-carrying props reach the DOM twice — once as `data-attr-text`, once as
 * rendered text — but props like `href` only ever become an attribute, so
 * "is this visible anywhere" has to look at both.
 */
export function visibleText(wrapper) {
    const attributes = wrapper.findAll('[data-stub]')
        .flatMap((node) => Object.entries(node.attributes())
            .filter(([key]) => key.startsWith('data-attr-'))
            .map(([, value]) => value))
        .join(' | ');

    return `${wrapper.text()} | ${attributes}`;
}
