/* eslint-disable */
import Injector from 'lib/Injector';
import AiMetadataActionButton from 'components/AiMetadataActionButton';
import AiMetadataModal from 'components/AiMetadataModal';
import ToggleCompositeField from 'components/ToggleCompositeField';

const registerComponents = () => {
  Injector.component.register('AiMetadataActionButton', AiMetadataActionButton);
  Injector.component.register('AiMetadataModal', AiMetadataModal);
  Injector.component.register('ToggleCompositeField', ToggleCompositeField);
};

export default registerComponents;
