/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('lib/Injector', () => ({
  __esModule: true,
  default: {
    component: {
      register: jest.fn(),
    },
  },
}), { virtual: true });

jest.mock('components/AiSeoActionButton', () => 'AiSeoActionButton', { virtual: true });
jest.mock('components/AiSeoModal', () => 'AiSeoModal', { virtual: true });
jest.mock('components/ToggleCompositeField', () => 'ToggleCompositeField', { virtual: true });

import Injector from 'lib/Injector';
import registerComponents from '../../src/boot/registerComponents';

test('registerComponents registers toolbar button, modal and field components', () => {
  registerComponents();

  expect(Injector.component.register).toHaveBeenNthCalledWith(1, 'AiSeoActionButton', 'AiSeoActionButton');
  expect(Injector.component.register).toHaveBeenNthCalledWith(2, 'AiSeoModal', 'AiSeoModal');
  expect(Injector.component.register).toHaveBeenNthCalledWith(3, 'ToggleCompositeField', 'ToggleCompositeField');
});
