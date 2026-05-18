/* eslint-disable */
import Injector from 'lib/Injector';
import AiSeoActionButton from 'components/AiSeoActionButton';
import AiSeoModal from 'components/AiSeoModal';
import ToggleCompositeField from 'components/ToggleCompositeField';

const registerComponents = () => {
  Injector.component.register('AiSeoActionButton', AiSeoActionButton);
  Injector.component.register('AiSeoModal', AiSeoModal);
  Injector.component.register('ToggleCompositeField', ToggleCompositeField);
};

export default registerComponents;
