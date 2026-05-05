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

jest.mock('components/AiMetadataActionButton', () => 'AiMetadataActionButton', { virtual: true });
jest.mock('components/AiMetadataModal', () => 'AiMetadataModal', { virtual: true });
jest.mock('components/ToggleCompositeField', () => 'ToggleCompositeField', { virtual: true });

import Injector from 'lib/Injector';
import registerComponents from '../registerComponents';

test('registerComponents registers toolbar button, modal and field components', () => {
  registerComponents();

  expect(Injector.component.register).toHaveBeenNthCalledWith(1, 'AiMetadataActionButton', 'AiMetadataActionButton');
  expect(Injector.component.register).toHaveBeenNthCalledWith(2, 'AiMetadataModal', 'AiMetadataModal');
  expect(Injector.component.register).toHaveBeenNthCalledWith(3, 'ToggleCompositeField', 'ToggleCompositeField');
});
