<?php

/**
 * Copyright 2017 Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Batch;

/**
 * An interface for storing the configuration.
 *
 * @experimental The experimental flag means that while we believe this method
 *      or class is ready for use, it may change before release in backwards-
 *      incompatible ways. Please use with caution, and test thoroughly when
 *      upgrading.
 */
interface ConfigStorageInterface
{
    /**
     * locks the storage
     *
     * @return bool
     */
    public function lock();
    /**
     * releases the lock
     *
     * @return bool
     */
    public function unlock();
    /**
     * saves the JobConfig to the storage
     * @param JobConfig $config A JobConfig to save.
     * @return bool true on success, false on failure
     */
    public function save(\DeliciousBrains\WP_Offload_Media\Gcp\Google\Cloud\Core\Batch\JobConfig $config);
    /**
     * loads the JobConfig from the storage
     *
     * @return JobConfig
     * @throws \RuntimeException when failed to load the JobConfig.
     */
    public function load();
    /**
     * Clear the JobConfig from storage.
     */
    public function clear();
}
