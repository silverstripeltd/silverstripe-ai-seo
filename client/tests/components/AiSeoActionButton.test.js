/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('components/Button/Button', () => {
  const React = jest.requireActual('react');

  return ({ children, className = '', color, icon, ...props }) => React.createElement(
    'button',
    {
      ...props,
      className: `btn ${color ? `btn-${color}` : ''} ${className}`.trim(),
    },
    icon ? React.createElement('span', { className: `btn__icon font-icon-${icon}`, 'aria-hidden': 'true' }) : null,
    children,
  );
}, { virtual: true });

import React from 'react';
import { render, screen } from '@testing-library/react';
import { AiSeoActionButton } from '../../src/components/AiSeoActionButton';

test('renders a share-style secondary toolbar button with SEO labelling', () => {
  const { container } = render(
    <AiSeoActionButton
      fqcn={'App\\Page'}
      recordId={7}
    />
  );

  const button = screen.getByRole('button', { name: 'SEO' });

  expect(button.className).toContain('ai-seo__action');
  expect(button.className).toContain('ai-seo-toolbar__button');
  expect(button.className).toContain('btn-secondary');
  expect(button.getAttribute('data-fqcn')).toBe('App\\Page');
  expect(button.getAttribute('data-record-id')).toBe('7');
  expect(button.getAttribute('title')).toBe('Edit SEO');
  expect(container.querySelector('.font-icon-tags')).not.toBeNull();
});
