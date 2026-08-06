import './styles.css';
import { LinguaCafeApp } from './ui';

const root = document.querySelector<HTMLElement>('#app');
if (!root) throw new Error('App root is missing');

new LinguaCafeApp(root).start();
