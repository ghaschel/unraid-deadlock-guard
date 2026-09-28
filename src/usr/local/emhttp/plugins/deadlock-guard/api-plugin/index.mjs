import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';
import path from 'node:path';
import { installAdapter } from './adapter.mjs';
import { apiVersionError } from './compatibility.mjs';
import { debugLog, errorClass, trace } from './debug.mjs';
import { codeHash, createRpc, publishStatus, clearStatus, installMarker } from './runtime.mjs';

const require = createRequire(import.meta.url);
const { Module, Logger } = require('@nestjs/common');
const { DiscoveryModule, DiscoveryService } = require('@nestjs/core');
const loadedHash = codeHash(path.dirname(fileURLToPath(import.meta.url)));

class ApiRuntime {
  constructor(discovery) {
    this.discovery = discovery;
    this.debug = debugLog;
    this.logger = new Logger('DeadlockGuard');
  }

  provider(name) {
    const instances = new Set(
      this.discovery
        .getProviders()
        .filter((item) => item.instance?.constructor?.name === name)
        .map((item) => item.instance),
    );
    if (instances.size !== 1) throw Error('Unsupported API provider: ' + name);
    return [...instances][0];
  }

  async onApplicationBootstrap() {
    let apiVersion;
    let stage = 'metadata';
    trace(this.debug, 'bootstrap.begin');
    try {
      const metadata = JSON.parse(fs.readFileSync('/usr/local/unraid-api/package.json', 'utf8'));
      apiVersion = metadata.version;
      stage = 'version';
      const versionError = apiVersionError(apiVersion);
      if (versionError) throw Error(versionError);
      stage = 'providers';
      const schemaHost = this.provider('GraphQLSchemaHost');
      const authorization = this.provider('AuthZService');
      if (typeof authorization.enforce !== 'function')
        throw Error('API authorization is unavailable');
      const services = { docker: this.provider('DockerService'), vm: this.provider('VmsService') };
      stage = 'interface';
      this.restore = installAdapter({
        schema: schemaHost.schema,
        services,
        authorize: (...args) => authorization.enforce(...args),
        enabled: () => fs.existsSync(installMarker),
        rpc: createRpc(loadedHash, { debug: this.debug }),
        debug: this.debug,
      });
      stage = 'status';
      publishStatus({ hash: loadedHash, apiVersion: metadata.version });
      trace(this.debug, 'bootstrap.success', { apiVersion });
      this.logger.log('API handoffs activated');
    } catch (error) {
      trace(this.debug, 'bootstrap.failure', {
        stage,
        apiVersion,
        reason: 'bootstrap_error',
        errorClass: errorClass(error),
      });
      // Leave the API itself available; surface unavailable protection in Settings.
      this.logger.error(error.message);
      try {
        publishStatus({ hash: loadedHash, apiVersion, error: error.message });
      } catch (statusError) {
        trace(this.debug, 'bootstrap.failure', {
          stage: 'status',
          reason: 'status_error',
          errorClass: errorClass(statusError),
        });
        this.logger.error(statusError.message);
      }
    }
  }

  onApplicationShutdown() {
    trace(this.debug, 'shutdown.begin');
    try {
      this.restore?.();
    } catch (error) {
      trace(this.debug, 'shutdown.failure', {
        stage: 'restore',
        reason: 'shutdown_error',
        errorClass: errorClass(error),
      });
      throw error;
    }
    try {
      clearStatus();
      trace(this.debug, 'shutdown.success');
    } catch (error) {
      trace(this.debug, 'shutdown.failure', {
        stage: 'status',
        reason: 'status_error',
        errorClass: errorClass(error),
      });
      this.logger.error(error.message);
    }
  }
}

export const adapter = 'nestjs';
export class ApiModule {}
Module({
  imports: [DiscoveryModule],
  providers: [
    {
      provide: ApiRuntime,
      useFactory: (discovery) => new ApiRuntime(discovery),
      inject: [DiscoveryService],
    },
  ],
})(ApiModule);
