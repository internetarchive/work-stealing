# Simple work stealing framework for Redis

* Jim Nelson <<jnelson@archive.org>>
* Internet Archive
* Presented at RedisConf 2019

## Introduction

"Work stealing" is a term originating from thread queue and operating system contexts. Here it's being used to indicate work being performed by workers across a distributed cluster of networked computers&mdash;Web servers, task queue workers, batch processing, and so on.

This library uses a basic approach to work stealing: Each defined Job registers with an organizing object (a Recruiter) and provides a rate from 0.0 to 1.0.  A simple random number generator determines if any worker is recruited to perform a small slice of work.  This simplicity means there's no need to store state, history, or provide some manner of priority management within the distributed cluster.

This library was designed with Redis in mind.  As such, sample code is included that demonstrates how to use work stealing with [Redis](https://redis.io/).  All code is in PHP and requires [Predis](https://github.com/nrk/predis/) to operate.

## License

WorkStealing is licensed under the [GNU Affero General Public License](https://www.gnu.org/licenses/agpl.html).  See the LICENSE file for more information.

## Concepts

In the context presented here, _work stealing_ is using spare cycles from workers around a distributed cluster to perform a "slice" or small amount of work.  The incremental work performed takes the place of a dedicated daemon, cron job, or service continuously at work.

In other words, rather than a single machine performing this background work (garbage collection, evicting expired fields, etc.), the work is performed across a distributed environment by any number of workers.

Good places to find these spare cycles is in places where a worker may have a "fast-exit" or "no-work" signal: cache hits, empty queues, no results from a database query, etc.  In this case, it's often acceptable for a worker to spend an extra 20 - 50 milliseconds performing some side work.

In the language of this library, these workers are _enlisted_.  They call a _recruiter_ which organizes all the available side-work (called _jobs_).

The library here uses a simple random number generator to determine if a worker should perform some extra work.  If it does, it has been _recruited_.  If it does not perform any side work, it's _dismissed_.

## Recruiter, Job

This library provides a **Recruiter** class.  This class organizes one or more **Job**s.  Each Job has an associated recruiting rate which indicates which percentage of enlisted workers it requires.

Each **Job**, in turn, must implemented a **recruited()** method.  This method is invoked when a worker in the cluster is selected to perform a slice of work.

A Job's recruited() method should be simple and relatively quick.  Errors and exceptions should simply return immediately; do not retry network errors, for example.  Likewise, held ocks and conditions where the caller would normally wait should be treated as a reason to exit as well.

The general strategy is _aggregated work_: If one recruit can't perform the job, it aborts and lets the next recruit make an attempt.

## To-do

This basic library is intended **solely for illustrative purposes**.  It's lacking in many respects:

 * Errors and exceptions should be logged for later forensics
 * Statistics (StatsD, Prometheus, etc.) should be gathered and monitored
 * A "setup" routine were Jobs may reliably be installed _before_ **Recruiter::enlist()** is invoked

Additionally, a more sophisticated algorithm could be envisioned that's better than a simple random number generator.  However, note that this approach means no state must be stored, no locks maintained, etc.

## More information

* [Work stealing (Wikipedia)](https://en.wikipedia.org/wiki/Work_stealing)
