import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.koopal.reader',
  appName: 'KooPal Reader',
  webDir: 'www',
  android: {
    allowMixedContent: true,
  },
};

export default config;
